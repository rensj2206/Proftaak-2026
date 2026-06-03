const express = require("express");
const http = require("http");
const { Server } = require("socket.io");
const path = require("path");

const app = express();
const server = http.createServer(app);
const io = new Server(server, { cors: { origin: "*" } });

app.use(express.static(path.join(__dirname, "public")));

// ─── Room Store ───────────────────────────────────────────────────────────────
// rooms[code] = {
//   code, joinMode: "open"|"request",
//   players: [socketId, socketId],
//   usernames: { socketId: string },
//   pendingRequests: [{ socketId, username }],
//   board, currentTurn, gameOver,
//   rematchVotes: Set<socketId>
// }
const rooms = {};

// ─── Helpers ──────────────────────────────────────────────────────────────────
function generateRoomCode() {
  const chars = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
  let code = "";
  for (let i = 0; i < 5; i++) code += chars[Math.floor(Math.random() * chars.length)];
  return code;
}

function createBoard() {
  return Array.from({ length: 6 }, () => Array(7).fill(null));
}

function dropToken(board, col, player) {
  for (let row = 5; row >= 0; row--) {
    if (!board[row][col]) { board[row][col] = player; return row; }
  }
  return -1;
}

function checkWin(board, row, col) {
  const player = board[row][col];
  if (!player) return null;
  const directions = [[0,1],[1,0],[1,1],[1,-1]];
  for (const [dr, dc] of directions) {
    const cells = [[row, col]];
    for (const sign of [-1, 1]) {
      let r = row + dr * sign, c = col + dc * sign;
      while (r >= 0 && r < 6 && c >= 0 && c < 7 && board[r][c] === player) {
        cells.push([r, c]); r += dr * sign; c += dc * sign;
      }
    }
    if (cells.length >= 4) return cells;
  }
  return null;
}

function checkDraw(board) {
  return board[0].every(cell => cell !== null);
}

function publicRoomList() {
  return Object.values(rooms)
    .filter(r => r.players.length === 1 && !r.gameOver)
    .map(r => ({
      code: r.code,
      host: r.usernames[r.players[0]] || "Unknown",
      joinMode: r.joinMode,
    }));
}

function broadcastLobby() {
  io.emit("lobbyUpdate", { rooms: publicRoomList() });
}

function startGame(room) {
  room.board = createBoard();
  room.currentTurn = 0;
  room.gameOver = false;
  room.rematchVotes = new Set();

  io.to(room.code).emit("gameStart", {
    board: room.board,
    currentTurn: room.currentTurn,
    usernames: room.usernames,
    players: room.players,
  });

  broadcastLobby();
  console.log(`[Room] ${room.code}: Game started — ${room.players.map(id => room.usernames[id]).join(" vs ")}`);
}

function cleanupRoom(code) {
  delete rooms[code];
  broadcastLobby();
}

// ─── Socket.IO ────────────────────────────────────────────────────────────────
io.on("connection", (socket) => {
  console.log(`[+] Connected: ${socket.id}`);

  // Username sent right after connect (comes from DB/auth later — for now client sends it)
  socket.on("setUsername", ({ username }) => {
    const name = (username || "").trim().slice(0, 20) || `Player_${socket.id.slice(0, 4)}`;
    socket.username = name;
    socket.emit("usernameAck", { username: name });
  });

  // ── GET LOBBY ──────────────────────────────────────────────────────────────
  socket.on("getLobby", () => {
    socket.emit("lobbyUpdate", { rooms: publicRoomList() });
  });

  // ── CREATE ROOM ────────────────────────────────────────────────────────────
 socket.on("createRoom", ({ joinMode } = {}) => {
    let code;
    do { code = generateRoomCode(); } while (rooms[code]);

    const username = socket.username || `Player_${socket.id.slice(0, 4)}`;

    rooms[code] = {
      code,
      joinMode: joinMode === "request" ? "request" : "open",
      players: [socket.id],
      usernames: { [socket.id]: username },
      pendingRequests: [],
      board: createBoard(),
      currentTurn: 0,
      gameOver: false,
      rematchVotes: new Set(),
    };

    socket.join(code);
    socket.roomCode = code;
    socket.playerIndex = 0;

    socket.emit("roomCreated", { roomCode: code, playerIndex: 0, joinMode: rooms[code].joinMode });
    broadcastLobby();
    console.log(`[Room] ${code} created by ${username} (mode=${rooms[code].joinMode})`);
  });

  // ── JOIN ROOM (by code OR lobby click) ────────────────────────────────────
  socket.on("joinRoom", ({ roomCode }) => {
    const code = (roomCode || "").toUpperCase().trim();
    const room = rooms[code];

    if (!room) { socket.emit("error", { message: "Room not found. Check the code and try again." }); return; }
    if (room.players.length >= 2) { socket.emit("error", { message: "Room is already full." }); return; }
    if (room.players.includes(socket.id)) { socket.emit("error", { message: "You are already in this room." }); return; }

    if (room.joinMode === "request") {
      _sendJoinRequest(socket, room);
    } else {
      _acceptPlayer(socket, room);
    }
  });

  // ── JOIN REQUEST FLOW ──────────────────────────────────────────────────────
  function _sendJoinRequest(socket, room) {
    if (room.pendingRequests.find(r => r.socketId === socket.id)) {
      socket.emit("joinRequestPending"); return;
    }

    const username = socket.username || `Player_${socket.id.slice(0, 4)}`;
    room.pendingRequests.push({ socketId: socket.id, username });
    socket.pendingRoom = room.code;

    socket.emit("joinRequestSent", {
      roomCode: room.code,
      hostName: room.usernames[room.players[0]],
    });

    const hostSocket = io.sockets.sockets.get(room.players[0]);
    if (hostSocket) hostSocket.emit("joinRequest", { socketId: socket.id, username });

    console.log(`[Room] ${room.code}: Join request from ${username}`);
  }

  socket.on("respondToRequest", ({ requesterSocketId, accepted }) => {
    const code = socket.roomCode;
    const room = rooms[code];
    if (!room || room.players[0] !== socket.id) return;

    const idx = room.pendingRequests.findIndex(r => r.socketId === requesterSocketId);
    if (idx === -1) return;

    const [req] = room.pendingRequests.splice(idx, 1);
    const requesterSocket = io.sockets.sockets.get(requesterSocketId);
    if (!requesterSocket) return;

    if (accepted) {
      if (room.players.length >= 2) {
        requesterSocket.emit("error", { message: "Room just filled up." }); return;
      }
      requesterSocket.emit("joinRequestAccepted", { roomCode: code });
      _acceptPlayer(requesterSocket, room);

      // Decline remaining pending
      room.pendingRequests.forEach(r => {
        const s = io.sockets.sockets.get(r.socketId);
        if (s) s.emit("joinRequestDeclined", { hostName: room.usernames[room.players[0]] });
      });
      room.pendingRequests = [];
    } else {
      requesterSocket.emit("joinRequestDeclined", { hostName: room.usernames[room.players[0]] });
      requesterSocket.pendingRoom = null;
    }
  });

  socket.on("cancelJoinRequest", () => {
    const code = socket.pendingRoom;
    const room = rooms[code];
    if (!room) return;

    room.pendingRequests = room.pendingRequests.filter(r => r.socketId !== socket.id);
    socket.pendingRoom = null;

    const hostSocket = io.sockets.sockets.get(room.players[0]);
    if (hostSocket) hostSocket.emit("joinRequestCancelled", { socketId: socket.id, username: socket.username });
  });

  function _acceptPlayer(playerSocket, room) {
    const username = playerSocket.username || `Player_${playerSocket.id.slice(0, 4)}`;
    const code = room.code;

    room.players.push(playerSocket.id);
    room.usernames[playerSocket.id] = username;

    playerSocket.join(code);
    playerSocket.roomCode = code;
    playerSocket.playerIndex = 1;
    playerSocket.pendingRoom = null;

    playerSocket.emit("roomJoined", { roomCode: code, playerIndex: 1 });
    startGame(room);
  }

  // ── DROP TOKEN ─────────────────────────────────────────────────────────────
  socket.on("dropToken", ({ col }) => {
    const code = socket.roomCode;
    const room = rooms[code];
    if (!room || room.gameOver) return;

    if (room.players[room.currentTurn] !== socket.id) {
      socket.emit("error", { message: "It's not your turn." }); return;
    }

    const playerLabel = room.currentTurn === 0 ? "red" : "yellow";
    const landedRow = dropToken(room.board, col, playerLabel);
    if (landedRow === -1) { socket.emit("error", { message: "That column is full." }); return; }

    const winningCells = checkWin(room.board, landedRow, col);
    if (winningCells) {
      room.gameOver = true;
      io.to(code).emit("gameUpdate", {
        board: room.board,
        lastMove: { row: landedRow, col, player: playerLabel },
        currentTurn: room.currentTurn,
      });
      io.to(code).emit("gameOver", {
        result: "win",
        winner: room.currentTurn,
        winnerName: room.usernames[socket.id],
        winningCells,
      });
      return;
    }

    if (checkDraw(room.board)) {
      room.gameOver = true;
      io.to(code).emit("gameUpdate", {
        board: room.board,
        lastMove: { row: landedRow, col, player: playerLabel },
        currentTurn: room.currentTurn,
      });
      io.to(code).emit("gameOver", { result: "draw" });
      return;
    }

    room.currentTurn = room.currentTurn === 0 ? 1 : 0;
    io.to(code).emit("gameUpdate", {
      board: room.board,
      lastMove: { row: landedRow, col, player: playerLabel },
      currentTurn: room.currentTurn,
    });
  });

  // ── FORFEIT ────────────────────────────────────────────────────────────────
  socket.on("forfeit", () => {
    const code = socket.roomCode;
    const room = rooms[code];
    if (!room || room.gameOver || room.players.length < 2) return;

    room.gameOver = true;
    const loserIndex = room.players.indexOf(socket.id);
    const winnerIndex = loserIndex === 0 ? 1 : 0;
    const winnerSocketId = room.players[winnerIndex];

    io.to(code).emit("gameOver", {
      result: "forfeit",
      winner: winnerIndex,
      winnerName: room.usernames[winnerSocketId],
      forfeitedName: socket.username,
    });

    console.log(`[Room] ${code}: ${socket.username} forfeited`);
  });

  // ── REMATCH VOTE ───────────────────────────────────────────────────────────
  socket.on("voteRematch", () => {
    const code = socket.roomCode;
    const room = rooms[code];
    if (!room || !room.gameOver || room.players.length < 2) return;

    room.rematchVotes.add(socket.id);

    io.to(code).emit("rematchVoteUpdate", {
      votes: room.rematchVotes.size,
      needed: 2,
      voterName: socket.username,
    });

    if (room.rematchVotes.size >= 2) startGame(room);
  });

  // ── EXIT GAME ──────────────────────────────────────────────────────────────
  socket.on("exitGame", () => {
    const code = socket.roomCode;
    if (!code || !rooms[code]) return;

    io.to(code).emit("opponentLeft", { leaverName: socket.username });
    socket.leave(code);
    socket.roomCode = null;
    cleanupRoom(code);
    console.log(`[Room] ${code}: Closed — ${socket.username} exited`);
  });

  // ── DISCONNECT ─────────────────────────────────────────────────────────────
  socket.on("disconnect", () => {
    console.log(`[-] Disconnected: ${socket.id}`);

    if (socket.pendingRoom && rooms[socket.pendingRoom]) {
      const room = rooms[socket.pendingRoom];
      room.pendingRequests = room.pendingRequests.filter(r => r.socketId !== socket.id);
      const hostSocket = io.sockets.sockets.get(room.players[0]);
      if (hostSocket) hostSocket.emit("joinRequestCancelled", { socketId: socket.id, username: socket.username });
    }

    const code = socket.roomCode;
    if (!code || !rooms[code]) return;

    io.to(code).emit("opponentLeft", { leaverName: socket.username || "Opponent" });
    cleanupRoom(code);
    console.log(`[Room] ${code}: Closed — player disconnected`);
  });
});

// ─── Start ────────────────────────────────────────────────────────────────────
const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
  console.log(`\n🎮 Connect Four server running at http://localhost:${PORT}\n`);
});