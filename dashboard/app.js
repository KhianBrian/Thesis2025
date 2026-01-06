let ws;

const hrData = [];
const spo2Data = [];
const labels = [];

const MAX_POINTS = 20;

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

  // Ignore heartbeat echoes
  if (data.type === "ping") return;

  const time = new Date(data.timestamp).toLocaleTimeString();

  if (data.type === "pr") {
    document.getElementById("hrValue").innerText = `${data.value} BPM`;
    hrData.push(data.value);
    labels.push(time);
  }

  if (data.type === "spo2") {
    document.getElementById("spo2Value").innerText = `${data.value} %`;
    spo2Data.push(data.value);
  }

  if (labels.length > MAX_POINTS) {
    labels.shift();
    hrData.shift();
    spo2Data.shift();
  }

  historyChart.update();
}
