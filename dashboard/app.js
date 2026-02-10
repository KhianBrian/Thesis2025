let ws;

const hrData = [];
const labels = [];
const MAX_POINTS = 20;
const MAX_LOGS = 12;

// Thresholds
const LOW_HR = 50;
const HIGH_HR = 100;

// ===============================
// WEBSOCKET WITH RECONNECT
// ===============================
let retryDelay = 1000;

function connectWS() {
  if (ws && ws.readyState !== WebSocket.CLOSED) {
    ws.close();
  }

  ws = new WebSocket("wss://thesis2025-h4v3.onrender.com/ws");

  ws.onopen = () => {
    console.log("[WS] Connected");
    retryDelay = 1000;
  };

  ws.onclose = () => {
    console.warn("[WS] Disconnected — reconnecting in", retryDelay);
    setTimeout(connectWS, retryDelay);
    retryDelay = Math.min(retryDelay * 2, 30000);
  };

  ws.onerror = () => {
    console.error("[WS] Error");
    ws.close();
  };

  ws.onmessage = handleMessage;
}

connectWS();

// HEARTBEAT
setInterval(() => {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ type: "ping" }));
  }
}, 30000);

// ===============================
// CHART
// ===============================
const historyChart = new Chart(document.getElementById("historyChart"), {
  type: "line",
  data: {
    labels,
    datasets: [
      {
        label: "Heart Rate",
        data: hrData,
        borderColor: "#3b82f6",
        tension: 0.3
      }
    ]
  },
  options: {
    animation: false,
    responsive: true,
    maintainAspectRatio: false
  }
});

// ===============================
// MESSAGE HANDLER
// ===============================
function handleMessage(event) {
  const data = JSON.parse(event.data);
  if (data.type === "ping") return;

  const time = new Date(data.timestamp).toLocaleTimeString();

  // HEART RATE
  if (data.type === "pr") {
    document.getElementById("hrValue").innerText = `${data.value} BPM`;
    hrData.push(data.value);
    labels.push(time);

    if (data.value < LOW_HR) logEvent(`Low Heart Rate: ${data.value} BPM`);
    if (data.value > HIGH_HR) logEvent(`High Heart Rate: ${data.value} BPM`);
  }

  // FALL
  if (data.type === "fall") {
    triggerFall();
    logEvent("Fall detected");
  }

  // LIMIT DATA
  if (labels.length > MAX_POINTS) {
    labels.shift();
    hrData.shift();
  }

  historyChart.update();
}

// ===============================
// FALL HANDLING
// ===============================
function triggerFall() {
  document.getElementById("fallStatus").innerText = "Fall Detected";
  document.getElementById("fallIcon").innerText = "🔴";

  const card = document.getElementById("fallStatusCard");
  card.classList.remove("normal");
  card.classList.add("fall");

  document.getElementById("fallModal").classList.remove("hidden");
}

function acknowledgeFall() {
  document.getElementById("fallModal").classList.add("hidden");
  document.getElementById("fallStatus").innerText = "Normal Movement";
  document.getElementById("fallIcon").innerText = "🟢";

  const card = document.getElementById("fallStatusCard");
  card.classList.remove("fall");
  card.classList.add("normal");
}

// ===============================
// ACTIVITY LOG
// ===============================
function logEvent(text) {
  const log = document.getElementById("activityLog");
  const li = document.createElement("li");
  li.innerText = `${new Date().toLocaleTimeString()} — ${text}`;

  log.prepend(li);
  if (log.children.length > MAX_LOGS) {
    log.removeChild(log.lastChild);
  }
}

// ===============================
// EVENT SEARCH MODAL
// ===============================
const openBtn = document.getElementById("openSearchModal");
const closeBtn = document.getElementById("closeSearchModal");
const modal = document.getElementById("searchModal");

openBtn.addEventListener("click", () => modal.classList.remove("hidden"));
closeBtn.addEventListener("click", () => modal.classList.add("hidden"));

modal.addEventListener("click", e => {
  if (e.target === modal) modal.classList.add("hidden");
});

document.addEventListener("keydown", e => {
  if (e.key === "Escape") modal.classList.add("hidden");
});

// ===============================
// EVENT SEARCH BACKEND
// ===============================
const loadingOverlay = document.getElementById("searchLoading");

document.getElementById("selectAll").addEventListener("change", e => {
  document.querySelectorAll(".eventType")
    .forEach(cb => cb.checked = e.target.checked);
});

document.getElementById("applyFilter").addEventListener("click", () => {
  const types = [...document.querySelectorAll(".eventType")]
    .filter(cb => cb.checked)
    .map(cb => cb.value);

  if (types.length === 0) {
    alert("Select at least one event type");
    return;
  }

  loadingOverlay.classList.remove("hidden");

  fetch("search_events.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      types,
      start: document.getElementById("startTime").value,
      end: document.getElementById("endTime").value
    })
  })
    .then(res => res.json())
    .then(data => {
      renderResults(data);
      loadingOverlay.classList.add("hidden");
    })
    .catch(err => {
      console.error("Search error:", err);
      loadingOverlay.classList.add("hidden");
      alert("Failed to fetch data.");
    });
});

function renderResults(data) {
  const tbody = document.querySelector("#resultsTable tbody");
  tbody.innerHTML = "";

  if (data.length === 0) {
    tbody.innerHTML = `<tr><td colspan="4">No results found.</td></tr>`;
    return;
  }

  data.forEach(row => {
    let value = "-";
    let height = "-";

    if (row.eventtype === "HeartRate") value = row.heartrate + " BPM";
    if (row.eventtype === "Fall") {
      value = "FALL";
      height = row.estimatedheight ?? "-";
    }

    tbody.insertAdjacentHTML("beforeend", `
      <tr>
        <td>${new Date(row.eventtime).toLocaleString()}</td>
        <td>${row.eventtype}</td>
        <td>${value}</td>
        <td>${height}</td>
      </tr>
    `);
  });
}

// ===============================
// THEME TOGGLE
// ===============================
const themeToggle = document.getElementById("themeToggle");

function updateThemeIcon() {
  themeToggle.textContent =
    document.body.classList.contains("light") ? "☀️" : "🌙";
}

themeToggle.addEventListener("click", () => {
  document.body.classList.toggle("light");
  updateThemeIcon();
});

updateThemeIcon();
