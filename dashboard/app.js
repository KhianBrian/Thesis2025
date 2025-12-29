const ws = new WebSocket("wss://thesis2025-h4v3.onrender.com/ws");

ws.onopen = () => console.log("WS Connected");
ws.onerror = e => console.error("WS Error", e);

const hrData = [];
const spo2Data = [];
const labels = [];

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
        },
        {
          label: "SpO₂",
          data: spo2Data,
          borderColor: "#10b981",
        }
      ]
    }
  }
);

ws.onmessage = (event) => {
  const data = JSON.parse(event.data);

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

  // Keep last 20 points
  if (labels.length > 20) {
    labels.shift();
    hrData.shift();
    spo2Data.shift();
  }

  historyChart.update();
};

