package site.bharatseo.bcreport;

import android.content.Context;

import androidx.annotation.NonNull;

import com.google.firebase.messaging.FirebaseMessaging;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;

import org.json.JSONObject;

import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.Map;

/** Firebase push: shows notifications / rings instantly. The 15-min PollWorker stays as backup. */
public class PushService extends FirebaseMessagingService {

    @Override
    public void onNewToken(@NonNull String token) {
        register(getApplicationContext(), token);
    }

    @Override
    public void onMessageReceived(@NonNull RemoteMessage msg) {
        Context c = getApplicationContext();
        if (Prefs.token(c).isEmpty()) {
            return;
        }
        Map<String, String> d = msg.getData();
        long id;
        try {
            id = Long.parseLong(d.getOrDefault("id", "0"));
        } catch (NumberFormatException e) {
            id = 0;
        }
        if (id > 0 && id <= Prefs.lastId(c)) {
            return; // already shown by the poller
        }
        String kind = d.getOrDefault("kind", "");
        if (kind.startsWith("ring_")) {
            Ringer.ring(c, "ring_call".equals(kind) ? "call" : "alarm");
        } else {
            Notifier.show(c, id, kind, d.getOrDefault("title", "BC Report"), d.getOrDefault("body", ""));
        }
        if (id > Prefs.lastId(c)) {
            Prefs.setLastId(c, id);
        }
        PollWorker.runNow(c); // also refreshes call/alarm settings + audio
    }

    /** Ask Firebase for this phone's token and send it to our server (after login / app start). */
    static void refresh(Context c) {
        try {
            FirebaseMessaging.getInstance().getToken().addOnSuccessListener(t -> register(c, t));
        } catch (Exception ignored) {
            // Firebase not available on this phone – polling still works
        }
    }

    static void register(Context c, String fcmToken) {
        final String token = Prefs.token(c);
        if (token.isEmpty() || fcmToken == null || fcmToken.isEmpty()) {
            return;
        }
        new Thread(() -> {
            HttpURLConnection con = null;
            try {
                con = (HttpURLConnection) new URL(BuildConfig.BASE_URL + "/api/app.php?a=fcm").openConnection();
                con.setRequestMethod("POST");
                con.setDoOutput(true);
                con.setConnectTimeout(10000);
                con.setReadTimeout(10000);
                con.setRequestProperty("Authorization", "Bearer " + token);
                con.setRequestProperty("Content-Type", "application/json");
                byte[] body = new JSONObject().put("token", fcmToken).toString().getBytes(StandardCharsets.UTF_8);
                try (OutputStream o = con.getOutputStream()) {
                    o.write(body);
                }
                con.getResponseCode();
            } catch (Exception ignored) {
            } finally {
                if (con != null) {
                    con.disconnect();
                }
            }
        }).start();
    }
}
