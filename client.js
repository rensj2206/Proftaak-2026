/* =============================================================
   Connect Four — Client Logic
   Communicates with server via Socket.IO.
   ============================================================= */

const socket = io();

// ---- DOM refs ----
const screens = {
  lobby:   document.getElementById("screen-lobby"),
  waiting: document.getElementById("screen-waiting"),
  game:    document.getElementById("screen-game"),
};

const lobbyError       = document.getElementById("lobby-error");
const displayRoomCode  = document.getElementById("display-room-code");
const copyConfirm      = document.getElementById("copy-confirm");
const boardEl          = document.getElementById("board");
const statusText       = document.getElementById("status-text");
const statusIndicator  = document.getElementById("status-indicator");
const gameOverOverlay  = document.getElementById("game-over-overlay");
const gameOverTitle    = document.getElementById("game-over-title");
const gameOverSub      = document.getElementById("game-over-sub");
const gameOverEmoji    = document.getElementById("game-over-emoji");
const scoreP1El        = document.getElementById("score-p1");
const scoreP2El        = document.getElementById("score-p2");
const badgeP1          = document.getElementById("badge-p1");
const badgeP2          = document.getElementById("badge-p2");
const hoverCells       = document.querySelectorAll(".hover-cell");

// ---- State ----
let myPlayerIndex = null;   // 0 = red, 1 = yellow
let currentTurn   = null;   // 0 or 1
let myTurn        = false;
let score         = [0, 0];
const ROWS = 6, COLS = 7;

// ---- Utility: Show a screen ----
function showScreen(name) {
  Object.entries(screens).forEach(([key, el]) => {
    el.style.display = "none";
    el.classList.remove("active");
  });
  screens[name].style.display = "flex";
  // Trigger animation by forcing reflow
  void screens[name].offsetWidth;
  screens[name].classList.add("active");
}

// ---- Build the board DOM ----
function buildBoard() {
  boardEl.innerHTML = "";
  for (let r = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++) {
      const cell = document.createElement("div");
      cell.classList.add("cell");
      cell.dataset.row = r;
      cell.dataset.col = c;
      boardEl.appendChild(cell);
    }
  }
}

// ---- Render board from server state ----
function renderBoard(board) {
  for (let r = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++) {
      const cell = document.querySelector(`.cell[data-row="${r}"][data-col="${c}"]`);
      if (!cell) continue;
      // Remove and re-add class cleanly (avoid re-triggering animation)
      cell.className = "cell";
      if (board[r][c]) cell.classList.add(board[r][c]);
    }
  }
}

// ---- Animate only the newly placed cell ----
function animateCell(row, col, player) {
  const cell = document.querySelector(`.cell[data-row="${row}"][data-col="${col}"]`);
  if (!cell) return;
  cell.className = "cell";
  void cell.offsetWidth; // reflow to restart animation
  cell.classList.add(player);
}

// ---- Highlight winning cells ----
function highlightWin(winningCells) {
  winningCells.forEach(([r, c]) => {
    const cell = document.querySelector(`.cell[data-row="${r}"][data-col="${c}"]`);
    if (cell) cell.classList.add("winning");
  });
}

// ---- Update status bar ----
function updateStatus() {
  const names = ["Player 1 (Red)", "Player 2 (Yellow)"];
  const colors = ["red", "yellow"];

  if (myTurn) {
    statusText.textContent = "Your turn";
    statusIndicator.className = colors[myPlayerIndex];
  } else {
    statusText.textContent = `${names[currentTurn]}'s turn`;
    statusIndicator.className = colors[currentTurn];
  }

  // Highlight active player badge
  badgeP1.classList.toggle("active", currentTurn === 0);
  badgeP2.classList.toggle("active", currentTurn === 1);
}

// ---- Column hover preview ----
function clearHover() {
  hoverCells.forEach(c => c.className = "hover-cell");
}

function setHover(col) {
  clearHover();
  if (!myTurn) return;
  const color = myPlayerIndex === 0 ? "preview-red" : "preview-yellow";
  const hoverCell = document.querySelector(`.hover-cell[data-col="${col}"]`);
  if (hoverCell) hoverCell.classList.add(color);
}

// ---- Show game-over overlay ----
function showGameOver(result, winner) {
  const names = ["Player 1 (Red)", "Player 2 (Yellow)"];
  if (result === "win") {
    const iWon = winner === myPlayerIndex;
    gameOverEmoji.textContent = iWon ? "🏆" : "😞";
    gameOverTitle.textContent = iWon ? "You Win!" : `${names[winner]} Wins!`;
    gameOverSub.textContent   = iWon ? "Excellent play!" : "Better luck next time";
  } else {
    gameOverEmoji.textContent = "🤝";
    gameOverTitle.textContent = "It's a Draw!";
    gameOverSub.textContent   = "No moves left";
  }
  gameOverOverlay.classList.remove("hidden");
}

// ================================================================
//  LOBBY BUTTONS
// ================================================================
document.getElementById("btn-create").addEventListener("click", () => {
  lobbyError.textContent = "";
  socket.emit("createRoom");
});

document.getElementById("btn-join").addEventListener("click", () => {
  const code = document.getElementById("input-room-code").value.trim().toUpperCase();
  if (!code || code.length < 4) {
    lobbyError.textContent = "Please enter a valid room code.";
    return;
  }
  lobbyError.textContent = "";
  socket.emit("joinRoom", { roomCode: code });
});

document.getElementById("input-room-code").addEventListener("keydown", (e) => {
  if (e.key === "Enter") document.getElementById("btn-join").click();
});

// ---- Copy room code ----
document.getElementById("btn-copy-code").addEventListener("click", () => {
  const code = displayRoomCode.textContent;
  navigator.clipboard.writeText(code).then(() => {
    copyConfirm.classList.add("show");
    setTimeout(() => copyConfirm.classList.remove("show"), 2000);
  });
});

// ================================================================
//  BOARD INTERACTION
// ================================================================
boardEl.addEventListener("click", (e) => {
  if (!myTurn) return;
  const cell = e.target.closest(".cell");
  if (!cell) return;
  const col = parseInt(cell.dataset.col, 10);
  socket.emit("dropToken", { col });
  myTurn = false; // Optimistic — server will correct if needed
});

boardEl.addEventListener("mousemove", (e) => {
  if (!myTurn) return;
  const cell = e.target.closest(".cell");
  if (!cell) return;
  setHover(parseInt(cell.dataset.col, 10));
});

boardEl.addEventListener("mouseleave", clearHover);

// Hover row
hoverCells.forEach(hc => {
  hc.addEventListener("mouseenter", () => {
    if (!myTurn) return;
    setHover(parseInt(hc.dataset.col, 10));
  });
  hc.addEventListener("click", () => {
    if (!myTurn) return;
    const col = parseInt(hc.dataset.col, 10);
    socket.emit("dropToken", { col });
    myTurn = false;
  });
});

// ---- Rematch ----
document.getElementById("btn-rematch").addEventListener("click", () => {
  socket.emit("requestRematch");
  gameOverOverlay.classList.add("hidden");
});

// ================================================================
//  SOCKET.IO EVENTS
// ================================================================
socket.on("roomCreated", ({ roomCode, playerIndex }) => {
  myPlayerIndex = playerIndex;
  displayRoomCode.textContent = roomCode;
  copyConfirm.style.opacity = "0";
  showScreen("waiting");
});

socket.on("roomJoined", ({ roomCode, playerIndex }) => {
  myPlayerIndex = playerIndex;
  displayRoomCode.textContent = roomCode;
});

socket.on("gameStart", ({ board, currentTurn: turn }) => {
  currentTurn = turn;
  myTurn = myPlayerIndex === turn;

  buildBoard();
  renderBoard(board);
  gameOverOverlay.classList.add("hidden");
  showScreen("game");
  updateStatus();
});

socket.on("gameUpdate", ({ board, lastMove, currentTurn: turn }) => {
  currentTurn = turn;
  myTurn = myPlayerIndex === turn;

  // Render full board state, then animate last piece
  renderBoard(board);
  if (lastMove) animateCell(lastMove.row, lastMove.col, lastMove.player);

  clearHover();
  updateStatus();
});

socket.on("gameOver", ({ result, winner, winningCells }) => {
  myTurn = false;
  clearHover();

  if (winningCells) highlightWin(winningCells);

  // Update score
  if (result === "win") {
    score[winner]++;
    scoreP1El.textContent = score[0];
    scoreP2El.textContent = score[1];
  }

  setTimeout(() => showGameOver(result, winner), winningCells ? 800 : 300);
});

socket.on("opponentLeft", () => {
  myTurn = false;
  statusText.textContent = "Opponent disconnected";
  statusIndicator.className = "";
  gameOverOverlay.classList.add("hidden");
  // Show a reconnect message then go back to lobby after a moment
  setTimeout(() => {
    score = [0, 0];
    scoreP1El.textContent = "0";
    scoreP2El.textContent = "0";
    document.getElementById("lobby-error").textContent = "";
    showScreen("lobby");
  }, 3000);
});

socket.on("error", ({ message }) => {
  // Show in lobby or as status depending on screen
  if (screens.lobby.classList.contains("active")) {
    lobbyError.textContent = message;
  } else {
    statusText.textContent = message;
    setTimeout(updateStatus, 2000);
  }
});

// ---- Initial screen ----
showScreen("lobby");