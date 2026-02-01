let ws;

const hrData = [];
const spo2Data = [];
const labels = [];
const MAX_POINTS = 20;
const MAX_LOGS = 12;

// Thresholds
const LOW_HR = 50;
const HIGH_HR = 100;
const LOW_SPO2 = 95;

// ===============================
// WEBSOCKET
// ===============================
function connectWS() {
  ws = new WebSocket("wss://thesis2025-h4v3.onrender.com/ws");

  ws.onopen = () => console.log("WS Connected");

  ws.onclose = () => {
    console.warn("WS Disconnected — retrying");
    setTimeout(connectWS, 3000);
  };

  ws.onerror = e => console.error("WS Error", e);
  ws.onmessage = handleMessage;
}

connectWS();

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
      { label: "Heart Rate", data: hrData, borderColor: "#3b82f6", tension: 0.3 },
      { label: "SpO₂", data: spo2Data, borderColor: "#10b981", tension: 0.3 }
    ]
  },
  options: { animation: false, responsive: true, maintainAspectRatio: false }
});

// ===============================
// MESSAGE HANDLER
// ===============================
function handleMessage(event) {
  const data = JSON.parse(event.data);
  if (data.type === "ping") return;

  const time = new Date(data.timestamp).toLocaleTimeString();

  if (data.type === "pr") {
    hrData.push(data.value);
    labels.push(time);
    document.getElementById("hrValue").innerText = `${data.value} BPM`;
  }

  if (data.type === "spo2") {
    spo2Data.push(data.value);
    document.getElementById("spo2Value").innerText = `${data.value} %`;
  }

  if (labels.length > MAX_POINTS) {
    labels.shift(); hrData.shift(); spo2Data.shift();
  }

  historyChart.update();
}

// ===============================
// EVENT SEARCH MODAL LOGIC
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
document.getElementById("selectAll").addEventListener("change", e => {
  document.querySelectorAll(".eventType")
    .forEach(cb => cb.checked = e.target.checked);
});

document.getElementById("applyFilter").addEventListener("click", () => {
  const types = [...document.querySelectorAll(".eventType")]
    .filter(cb => cb.checked)
    .map(cb => cb.value);

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
    .then(renderResults);
});

function renderResults(data) {
  const tbody = document.querySelector("#resultsTable tbody");
  tbody.innerHTML = "";

  data.forEach(row => {
    let value = "-";
    let height = "-";

    if (row.eventtype === "HeartRate") value = row.heartrate + " BPM";
    if (row.eventtype === "SpO2") value = row.spo2 + " %";
    if (row.eventtype === "Fall") {
      value = "FALL";
      height = row.estimatedheight ?? "-";
    }

    tbody.innerHTML += `
      <tr>
        <td>${new Date(row.eventtime).toLocaleString()}</td>
        <td>${row.eventtype}</td>
        <td>${value}</td>
        <td>${height}</td>
      </tr>`;
  });
}

// ===============================
// THEME TOGGLE ICON (SUN / MOON)
// ===============================
const themeToggle = document.getElementById("themeToggle");

function updateThemeIcon() {
  if (document.body.classList.contains("light")) {
    themeToggle.textContent = "☀️";
  } else {
    themeToggle.textContent = "🌙";
  }
}

themeToggle.addEventListener("click", () => {
  document.body.classList.toggle("light");
  updateThemeIcon();
});

// Set correct icon on page load
updateThemeIcon();

