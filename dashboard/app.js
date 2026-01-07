let ws;

const hrData = [];
const spo2Data = [];
const labels = [];

const MAX_POINTS = 20;
const MAX_LOGS = 12;

// ================= DOM ELEMENTS =================
const activityLog = document.getElementById("activityLog");
const fallModal = document.getElementById("fallModal");
const fallStatus = document.getElementById("fallStatus");
const fallCard = document.getElementById("fallStatusCard");

// ================= CONNECT WS =================
function connectWS() {
  ws = new WebSocket("wss://thesis2025-h4v3.onrender.com/ws");

  ws.onopen = () => {
    console.log("WS Connected");
  };

  ws.onerror = (e) => {
    console.error("WS Error", e);
  };

  ws.onclose = () => {
    console.warn("WS Disconnected — retrying in 3s");
    setTimeout(connectWS, 3000);
  };

  ws.onmessage = handleMessage;
}

connectWS();

// ================= HEARTBEAT =================
setInterval(() => {
  if (ws && ws.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ type: "ping" }));
  }
}, 30000);

// ================= ACTIVITY LOG =================
function logEvent(text) {
  const li = document.createElement("li");
  li.textContent = `${new Date().toLocaleTimeString()} — ${text}`;
  activityLog.prepend(li);

  while (activityLog.children.length > MAX_LOGS) {
    activityLog.removeChild(activityLog.lastChild);
  }
}

// ================= FALL ALERT =================
function showFallAlert() {
  fallModal.classList.remove("hidden");
  fallStatus.textContent = "FALL DETECTED";
  fallCard.classList.remove("normal");
  fallCard.classList.add("fall");
}

function acknowledgeFall() {
  fallModal.classList.add("hidden");
  fallStatus.textContent = "Normal Movement";
  fallCard.classList.remove("fall");
  fallCard.classList.add("normal");
}

// ================= CHART =================
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
      scales: {
        y: {
          beginAtZero: false
        }
      }
    }
  }
);

// ================= MESSAGE HANDLER =================
function handleMessage(event) {
  const data = JSON.parse(event.data);

  // Ignore heartbeat
  if (data.type === "ping") return;

  const time = new Date(data.timestamp).toLocaleTimeString();

  // ---- HEART RATE ----
  if (data.type === "pr") {
    document.getElementById("hrValue").innerText = `${data.value} BPM`;

    if (data.value > 100) {
      logEvent(`High BPM detected: ${data.value} BPM`);
    } else if (data.value < 50) {
      logEvent(`Low BPM detected: ${data.value} BPM`);
    }

    hrData.push(data.value);
    labels.push(time);
  }

  // ---- SPO2 ----
  if (data.type === "spo2") {
    document.getElementById("spo2Value").innerText = `${data.value} %`;

    if (data.value < 95) {
      logEvent(`Low SpO₂ detected: ${data.value}%`);
    }

    spo2Data.push(data.value);
  }

  // ---- FALL ----
  if (data.type === "fall") {
    logEvent("Fall detected");
    showFallAlert();
  }

  // ---- KEEP HISTORY SHORT ----
  if (labels.length > MAX_POINTS) {
    labels.shift();
    hrData.shift();
    spo2Data.shift();
  }

  historyChart.update();
}
