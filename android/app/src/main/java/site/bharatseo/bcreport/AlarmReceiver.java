package site.bharatseo.bcreport;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Fired at the call / alarm time: rings only if today's report is still pending. */
public class AlarmReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        final Context c = context.getApplicationContext();
        AlarmScheduler.scheduleAll(c); // set tomorrow's
        if (!AlarmScheduler.ACTION_RING.equals(intent.getAction())) {
            return;
        }
        final String mode = "call".equals(intent.getStringExtra("mode")) ? "call" : "alarm";
        if (Prefs.reportDoneToday(c) || !Prefs.alarmEnabled(c)) {
            AlarmScheduler.cancelRepeat(c);
            return;
        }
        final PendingResult pr = goAsync();
        new Thread(() -> {
            try {
                Boolean ring = Api.shouldRing(c);
                if (ring == null || ring) { // no internet -> ring anyway (better safe)
                    Ringer.ring(c, mode);
                    AlarmScheduler.scheduleRepeat(c, mode); // re-rings every 10 min until the report is submitted
                } else {

                    AlarmScheduler.cancelRepeat(c); // submitted / leave / holiday / after cutoff
                }
            } finally {
                pr.finish();
            }
        }).start();
    }
}
