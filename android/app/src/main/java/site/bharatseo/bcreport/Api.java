package site.bharatseo.bcreport;

import android.content.Context;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

/** Small blocking HTTP helpers (call from a background thread). */
final class Api {
    private Api() {
    }

    /** GET /api/app.php?a=...; returns JSON or null on network error; "{"_401":true}" when logged out. */
    static JSONObject get(Context c, String query) {
        String token = Prefs.token(c);
        if (token.isEmpty()) {
            return null;
        }
        HttpURLConnection con = null;
        try {
            con = (HttpURLConnection) new URL(BuildConfig.BASE_URL + "/api/app.php?" + query).openConnection();
            con.setConnectTimeout(8000);
            con.setReadTimeout(10000);
            con.setRequestProperty("Authorization", "Bearer " + token);
            con.setRequestProperty("Accept", "application/json");
            int code = con.getResponseCode();
            if (code == 401) {
                return new JSONObject().put("_401", true);
            }
            if (code != 200) {
                return null;
            }
            return new JSONObject(read(con.getInputStream()));
        } catch (Exception e) {
            return null;
        } finally {
            if (con != null) {
                con.disconnect();
            }
        }
    }

    /** true = report pending -> ring, false = no ring, null = could not check. */
    static Boolean shouldRing(Context c) {
        JSONObject j = get(c, "a=alarm_check");
        if (j == null) {
            return null;
        }
        if (j.optBoolean("_401")) {
            return false;
        }
        JSONObject cfg = j.optJSONObject("config");
        if (cfg != null) {
            applyConfig(c, cfg);
        }
        return j.optBoolean("ring", true);
    }

    /** Store call/alarm config, download the recorded audio when it changed, re-schedule. */
    static void applyConfig(Context c, JSONObject cfg) {
        int version = cfg.optInt("version", 0);
        boolean changed = version != Prefs.alarmVersion(c);
        Prefs.saveAlarm(c, cfg.optBoolean("enabled", true), cfg.optString("call_time", "17:00"),
                cfg.optString("alarm_time", "17:30"), cfg.optString("text", ""));
        if (changed) {
            String url = cfg.optString("audio_url", "");
            File f = new File(c.getFilesDir(), "call_audio");
            boolean ok = true;
            if (url.isEmpty()) {
                //noinspection ResultOfMethodCallIgnored
                f.delete();
            } else {
                ok = download(url, f);
            }
            if (ok) {
                Prefs.setAlarmVersion(c, version);
            }
        }
        AlarmScheduler.scheduleAll(c);
    }

    private static boolean download(String url, File target) {
        HttpURLConnection con = null;
        File tmp = new File(target.getPath() + ".tmp");
        try {
            con = (HttpURLConnection) new URL(url).openConnection();
            con.setConnectTimeout(10000);
            con.setReadTimeout(30000);
            if (con.getResponseCode() != 200) {
                return false;
            }
            try (InputStream in = con.getInputStream(); FileOutputStream out = new FileOutputStream(tmp)) {
                byte[] buf = new byte[16384];
                int n;
                while ((n = in.read(buf)) > 0) {
                    out.write(buf, 0, n);
                }
            }
            return tmp.renameTo(target);
        } catch (Exception e) {
            return false;
        } finally {
            if (con != null) {
                con.disconnect();
            }
        }
    }

    static String read(InputStream in) throws java.io.IOException {
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        byte[] buf = new byte[8192];
        int n;
        while ((n = in.read(buf)) > 0) {
            out.write(buf, 0, n);
        }
        return out.toString(StandardCharsets.UTF_8.name());
    }
}
