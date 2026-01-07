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

// CONNECT WS
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

// HEARTBEAT
setInterval(() => {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ type: "ping" }));
  }
}, 30000);

// CHART
const historyChart = new Chart(
  document.getElementById("historyChart"),
  {
    type: "line",
    data: {
      labels,
      datasets: [
        {
          label: "Heart Rate",
          data: hrData,
          borderColor: "#3b82f6",
          tension: 0.3
        },
        {
          label: "SpO₂",
          data: spo2Data,
          borderColor: "#10b981",
          tension: 0.3
        }
      ]
    },
    options: {
      animation: false,
      responsive: true,
      maintainAspectRatio: false
    }
  }
);

// MESSAGE HANDLER
function handleMessage(event) {
  const data = JSON.parse(event.data);
  if (data.type === "ping") return;

  const time = new Date(data.timestamp).toLocaleTimeString();

  // HEART RATE
  if (data.type === "pr") {
    document.getElementById("hrValue").innerText = `${data.value} BPM`;
    hrData.push(data.value);
    labels.push(time);

    if (data.value < LOW_HR) {
      logEvent(`Low Heart Rate detected: ${data.value} BPM`);
    }

    if (data.value > HIGH_HR) {
      logEvent(`High Heart Rate detected: ${data.value} BPM`);
    }
  }

  // SPO2
  if (data.type === "spo2") {
    document.getElementById("spo2Value").innerText = `${data.value} %`;
    spo2Data.push(data.value);

    if (data.value < LOW_SPO2) {
      logEvent(`Low SpO₂ detected: ${data.value}%`);
    }
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
    spo2Data.shift();
  }

  historyChart.update();
}

// FALL HANDLING
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

// ACTIVITY LOG (ABNORMAL EVENTS ONLY)
function logEvent(text) {
  const log = document.getElementById("activityLog");
  const li = document.createElement("li");
  li.innerText = `${new Date().toLocaleTimeString()} — ${text}`;

  log.prepend(li);
  if (log.children.length > MAX_LOGS) {
    log.removeChild(log.lastChild);
  }
}

// THEME
function toggleTheme() {
  document.body.classList.toggle("light");
}
