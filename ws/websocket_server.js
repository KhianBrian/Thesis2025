const WebSocket = require("ws");
const pool = require("./db");

const PORT = process.env.PORT || 10000;

const wss = new WebSocket.Server({
  port: PORT,
  path: "/ws"
});

console.log("WebSocket server running on /ws");

wss.on("connection", (ws) => {
  console.log("Client connected");

  ws.on("message", async (message) => {
    let data;

    try {
      data = JSON.parse(message.toString());
    } catch {
      return;
    }

    // Ignore heartbeat
    if (data.type === "ping") return;

    console.log("Received:", data);

    try {

      if (!data.patient_id || !data.event_id) {
        throw new Error("Missing patient_id or event_id");
      }

      const patientId = data.patient_id;

      // ==========================
      // HEART RATE EVENT MIRROR
      // ==========================
      if (data.type === "pr") {

        if (!data.level) {
          throw new Error("Missing HR level in payload");
        }

        // Insert parent event
        await pool.query(
          `INSERT INTO event_table (eventid, patientid, eventtype)
           VALUES ($1, $2, 'HeartRate')
           ON CONFLICT (eventid) DO NOTHING`,
          [data.event_id, patientId]
        );

        // Insert HR details
        await pool.query(
          `INSERT INTO hr_event (eventid, heartrate, heartratelevel)
           VALUES ($1, $2, $3)
           ON CONFLICT (eventid) DO NOTHING`,
          [data.event_id, data.value, data.level]
        );
      }

      // ==========================
      // FALL EVENT MIRROR
      // ==========================
      if (data.type === "fall") {

        if (!data.height_event_id) {
          throw new Error("Missing height_event_id for fall");
        }

        // Insert Fall parent event
        await pool.query(
          `INSERT INTO event_table (eventid, patientid, eventtype)
           VALUES ($1, $2, 'Fall')
           ON CONFLICT (eventid) DO NOTHING`,
          [data.event_id, patientId]
        );

        await pool.query(
          `INSERT INTO fall_event (eventid, severity)
           VALUES ($1, 'Moderate')
           ON CONFLICT (eventid) DO NOTHING`,
          [data.event_id]
        );

        // Insert Height parent event
        await pool.query(
          `INSERT INTO event_table (eventid, patientid, eventtype)
           VALUES ($1, $2, 'Height')
           ON CONFLICT (eventid) DO NOTHING`,
          [data.height_event_id, patientId]
        );

        await pool.query(
          `INSERT INTO height_event (eventid, heightcm)
           VALUES ($1, $2)
           ON CONFLICT (eventid) DO NOTHING`,
          [data.height_event_id, data.height * 100]
        );
      }

    } catch (err) {
      console.error("DB Insert Error:", err);
    }

    // Broadcast after DB insert
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