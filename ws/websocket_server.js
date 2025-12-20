const WebSocket = require("ws");

const PORT = process.env.PORT || 10000;

const wss = new WebSocket.Server({ port: PORT });

console.log("WebSocket server running on port", PORT);

wss.on("connection", (ws) => {
  console.log("Client connected");

  ws.on("message", (message) => {
    try {
      const data = JSON.parse(message.toString());
      console.log("Received:", data);
    } catch (err) {
      console.error("Invalid JSON:", message.toString());
    }
  });

  ws.on("close", () => {
    console.log("Client disconnected");
  });
});
