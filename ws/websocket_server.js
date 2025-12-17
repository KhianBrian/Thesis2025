const WebSocket = require("ws");

const PORT = process.env.PORT || 3000;
const wss = new WebSocket.Server({ port: PORT });

console.log("WebSocket server running on port", PORT);

wss.on("connection", (ws) => {
  console.log("Client connected");

  ws.on("message", (data) => {
    console.log("Broadcasting:", data.toString());

    // Broadcast to all connected clients
    wss.clients.forEach(client => {
      if (client.readyState === WebSocket.OPEN) {
        client.send(data.toString());
      }
    });
  });

  ws.on("close", () => console.log("Client disconnected"));
});
