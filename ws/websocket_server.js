const WebSocket = require("ws");

const PORT = process.env.PORT || 10000;

const wss = new WebSocket.Server({
  port: PORT,
  path: "/ws"
});

console.log("WebSocket server running on /ws");

wss.on("connection", (ws) => {
  console.log("Client connected");

  ws.on("message", (message) => {
    let data;

    try {
      data = JSON.parse(message.toString());
      console.log("Received:", data);
    } catch (e) {
      console.error("Invalid JSON:", message.toString());
      return;
    }

    // 🔴 BROADCAST TO ALL CONNECTED CLIENTS
    wss.clients.forEach((client) => {
      if (client.readyState === WebSocket.OPEN) {
        client.send(JSON.stringify(data));
      }
    });
  });

  ws.on("close", () => {
    console.log("Client disconnected");
  });
});
