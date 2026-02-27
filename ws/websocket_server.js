const express = require("express");
const http = require("http");
const WebSocket = require("ws");
const pool = require("./db");

const PORT = process.env.PORT || 10000;

const app = express();
app.use(express.json());

// ==============================
// BASIC HEALTH CHECK ROUTE
// ==============================
app.get("/", (req, res) => {
  res.send("VitalLink Cloud Backend Running");
});

// ==============================
// USER + ADMIN + PATIENT + THRESHOLD SYNC
// ==============================
app.post("/sync-user", async (req, res) => {
  try {

    const syncKey = req.headers["x-sync-key"];
    if (!syncKey || syncKey !== process.env.SYNC_SECRET) {
      return res.status(403).json({ error: "Unauthorized" });
    }

    const { user, admin, patient, threshold } = req.body;

    if (!user) {
      return res.status(400).json({ error: "Missing user data" });
    }

    // ==========================
    // UPSERT user_table
    // ==========================
    await pool.query(
      `INSERT INTO user_table
       (userid, username, passwordhash, phonenumber, email, role, camerastatus)
       VALUES ($1,$2,$3,$4,$5,$6,$7)
       ON CONFLICT (userid)
       DO UPDATE SET
         username = EXCLUDED.username,
         passwordhash = EXCLUDED.passwordhash,
         phonenumber = EXCLUDED.phonenumber,
         email = EXCLUDED.email,
         role = EXCLUDED.role,
         camerastatus = EXCLUDED.camerastatus`,
      [
        user.userid,
        user.username,
        user.passwordhash,
        user.phonenumber,
        user.email,
        user.role,
        user.camerastatus || "Inactive"
      ]
    );

    // ==========================
    // UPSERT admin_table (optional)
    // ==========================
    if (admin) {
      await pool.query(
        `INSERT INTO admin_table
         (adminid, userid, fullname)
         VALUES ($1,$2,$3)
         ON CONFLICT (adminid)
         DO UPDATE SET
           fullname = EXCLUDED.fullname,
           userid = EXCLUDED.userid`,
        [
          admin.adminid,
          admin.userid,
          admin.fullname
        ]
      );
    }

    // ==========================
    // UPSERT patient_table (optional)
    // ==========================
    if (patient) {
      await pool.query(
        `INSERT INTO patient_table
         (patientid, userid, adminid, firstname, middlename, lastname,
          birthdate, age, gender, phonenumber,
          addressline, city, province, postalcode,
          emergencycontactname, emergencycontactnumber, emergencyrelationship)
         VALUES
         ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17)
         ON CONFLICT (patientid)
         DO UPDATE SET
           firstname = EXCLUDED.firstname,
           middlename = EXCLUDED.middlename,
           lastname = EXCLUDED.lastname,
           birthdate = EXCLUDED.birthdate,
           age = EXCLUDED.age,
           gender = EXCLUDED.gender,
           phonenumber = EXCLUDED.phonenumber,
           addressline = EXCLUDED.addressline,
           city = EXCLUDED.city,
           province = EXCLUDED.province,
           postalcode = EXCLUDED.postalcode,
           emergencycontactname = EXCLUDED.emergencycontactname,
           emergencycontactnumber = EXCLUDED.emergencycontactnumber,
           emergencyrelationship = EXCLUDED.emergencyrelationship,
           adminid = EXCLUDED.adminid`,
        [
          patient.patientid,
          patient.userid,
          patient.adminid || null,
          patient.firstname,
          patient.middlename,
          patient.lastname,
          patient.birthdate,
          patient.age,
          patient.gender,
          patient.phonenumber,
          patient.addressline,
          patient.city,
          patient.province,
          patient.postalcode,
          patient.emergencycontactname,
          patient.emergencycontactnumber,
          patient.emergencyrelationship
        ]
      );
    }

    // ==========================
    // UPSERT hr_threshold_table (optional)
    // ==========================
    if (threshold) {
      await pool.query(
        `INSERT INTO hr_threshold_table
         (thresholdid, patientid, restingmin, restingmax,
          activemin, activemax, criticallevel)
         VALUES ($1,$2,$3,$4,$5,$6,$7)
         ON CONFLICT (thresholdid)
         DO UPDATE SET
           restingmin = EXCLUDED.restingmin,
           restingmax = EXCLUDED.restingmax,
           activemin = EXCLUDED.activemin,
           activemax = EXCLUDED.activemax,
           criticallevel = EXCLUDED.criticallevel`,
        [
          threshold.thresholdid,
          threshold.patientid,
          threshold.restingmin,
          threshold.restingmax,
          threshold.activemin,
          threshold.activemax,
          threshold.criticallevel
        ]
      );
    }

    res.json({ success: true });

  } catch (err) {
    console.error("SYNC ERROR:", err);
    res.status(500).json({ error: "Sync failed" });
  }
});

// ==============================
// SYNC-EVENT: HTTP endpoint for receiver.py to push events with PST timestamp
// ==============================
app.post("/sync-event", async (req, res) => {
  try {
    const syncKey = req.headers["x-sync-key"];
    if (!syncKey || syncKey !== process.env.SYNC_SECRET) {
      return res.status(403).json({ error: "Unauthorized" });
    }

    const { eventtype, patientid, eventid, eventtime,
            heartrate, heartratelevel,
            severity, heightcm } = req.body;

    if (!eventtype || !patientid || !eventid) {
      return res.status(400).json({ error: "Missing required fields" });
    }

    const ts = eventtime || new Date().toISOString();

    // Upsert into event_table with correct PST timestamp
    await pool.query(
      `INSERT INTO event_table (eventid, patientid, eventtype, eventtime)
       VALUES ($1, $2, $3, $4)
       ON CONFLICT (eventid) DO UPDATE SET eventtime = EXCLUDED.eventtime`,
      [eventid, patientid, eventtype, ts]
    );

    // Insert into child table based on type
    if (eventtype === "HeartRate" && heartrate != null) {
      await pool.query(
        `INSERT INTO hr_event (eventid, heartrate, heartratelevel)
         VALUES ($1, $2, $3)
         ON CONFLICT (eventid) DO NOTHING`,
        [eventid, heartrate, heartratelevel || "Normal"]
      );
    } else if (eventtype === "Fall") {
      await pool.query(
        `INSERT INTO fall_event (eventid, severity)
         VALUES ($1, $2)
         ON CONFLICT (eventid) DO NOTHING`,
        [eventid, severity || "Moderate"]
      );
    } else if (eventtype === "Height" && heightcm != null) {
      await pool.query(
        `INSERT INTO height_event (eventid, heightcm)
         VALUES ($1, $2)
         ON CONFLICT (eventid) DO NOTHING`,
        [eventid, heightcm]
      );
    }

    res.json({ success: true });
  } catch (err) {
    console.error("SYNC-EVENT ERROR:", err);
    res.status(500).json({ error: "Sync event failed", detail: err.message });
  }
});

// ==============================
// CREATE HTTP SERVER
// ==============================
const server = http.createServer(app);

// ==============================
// ATTACH WEBSOCKET TO SAME SERVER
// ==============================
const wss = new WebSocket.Server({
  server,
  path: "/ws"
});

console.log("WebSocket ready on /ws");

// ==============================
// WEBSOCKET HANDLER
// ==============================
wss.on("connection", (ws) => {
  console.log("Client connected");

  ws.on("message", async (message) => {
    let data;

    try {
      data = JSON.parse(message.toString());
    } catch {
      return;
    }

    if (data.type === "ping") return;

    console.log("Received:", data);

    try {
      if (!data.patient_id || !data.event_id) {
        throw new Error("Missing patient_id or event_id");
      }

      const patientId = data.patient_id;

      // ==========================
      // HEART RATE MIRROR
      // ==========================
      if (data.type === "pr") {

        if (!data.level) {
          throw new Error("Missing HR level in payload");
        }

        const hrTime = data.timestamp || new Date().toISOString();
        await pool.query(
          `INSERT INTO event_table (eventid, patientid, eventtype, eventtime)
           VALUES ($1, $2, 'HeartRate', $3)
           ON CONFLICT (eventid) DO NOTHING`,
          [data.event_id, patientId, hrTime]
        );

        await pool.query(
          `INSERT INTO hr_event (eventid, heartrate, heartratelevel)
           VALUES ($1, $2, $3)
           ON CONFLICT (eventid) DO NOTHING`,
          [data.event_id, data.value, data.level]
        );
      }

      // ==========================
      // FALL MIRROR
      // ==========================
      if (data.type === "fall") {

        if (!data.height_event_id) {
          throw new Error("Missing height_event_id");
        }

        const fallTime = data.timestamp || new Date().toISOString();
        await pool.query(
          `INSERT INTO event_table (eventid, patientid, eventtype, eventtime)
           VALUES ($1, $2, 'Fall', $3)
           ON CONFLICT (eventid) DO NOTHING`,
          [data.event_id, patientId, fallTime]
        );

        await pool.query(
          `INSERT INTO fall_event (eventid, severity)
           VALUES ($1, 'Moderate')
           ON CONFLICT (eventid) DO NOTHING`,
          [data.event_id]
        );

        await pool.query(
          `INSERT INTO event_table (eventid, patientid, eventtype, eventtime)
           VALUES ($1, $2, 'Height', $3)
           ON CONFLICT (eventid) DO NOTHING`,
          [data.height_event_id, patientId, fallTime]
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

    // Broadcast
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

// ==============================
// START SERVER
// ==============================
server.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});