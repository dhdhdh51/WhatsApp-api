package site.bharatseo.bcreport;

import android.content.Context;

import androidx.annotation.NonNull;
import androidx.work.Constraints;
import androidx.work.ExistingPeriodicWorkPolicy;
import androidx.work.NetworkType;
import androidx.work.PeriodicWorkRequest;
import androidx.work.WorkManager;
import androidx.work.Worker;
import androidx.work.WorkerParameters;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.TimeUnit;

/** Every 15 minutes (Android minimum): asks the server for new notifications and shows them. */
public class PollWorker extends Worker {

    public PollWorker(@NonNull Context context, @NonNull WorkerParameters params) {
        super(context, params);
    }

    static void schedule(Context c) {
        PeriodicWorkRequest req = new PeriodicWorkRequest.Builder(PollWorker.class, 15, TimeUnit.MINUTES)
                .setConstraints(new Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build();
        WorkManager.getInstance(c).enqueueUniquePeriodicWork("bc_poll", ExistingPeriodicWorkPolicy.KEEP, req);
    }

    @NonNull
    @Override
    public Result doWork() {
        Context c = getApplicationContext();
        String token = Prefs.token(c);
        if (token.isEmpty()) {
            return Result.success();
        }
        long last = Prefs.lastId(c);
        HttpURLConnection con = null;
        try {
            URL url = new URL(BuildConfig.BASE_URL + "/api/app.php?a=poll&since=" + last);
            con = (HttpURLConnection) url.openConnection();
            con.setConnectTimeout(15000);
            con.setReadTimeout(20000);
            con.setRequestProperty("Authorization", "Bearer " + token);
            con.setRequestProperty("Accept", "application/json");
            int code = con.getResponseCode();
            if (code == 401) {
                Prefs.clear(c);
                return Result.success();
            }
            if (code != 200) {
                return Result.retry();
            }
            JSONObject j = new JSONObject(read(con.getInputStream()));
            long serverLast = j.optLong("last", last);
            if (last == 0) {
                Prefs.setLastId(c, serverLast); // first run: don't replay old notifications
                return Result.success();
            }
            JSONArray arr = j.optJSONArray("notifications");
            long max = last;
            if (arr != null) {
                for (int i = 0; i < arr.length(); i++) {
                    JSONObject n = arr.getJSONObject(i);
                    long id = n.getLong("id");
                    Notifier.show(c, id, n.optString("kind", ""), n.optString("title", "BC Report"), n.optString("body", ""));
                    max = Math.max(max, id);
                }
            }
            Prefs.setLastId(c, (arr == null || arr.length() == 0) ? Math.max(last, serverLast) : max);
            return Result.success();
        } catch (Exception e) {
            return Result.retry();
        } finally {
            if (con != null) {
                con.disconnect();
            }
        }
    }

    private static String read(InputStream in) throws java.io.IOException {
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        byte[] buf = new byte[8192];
        int n;
        while ((n = in.read(buf)) > 0) {
            out.write(buf, 0, n);
        }
        return out.toString(StandardCharsets.UTF_8.name());
    }
}
