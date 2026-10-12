package site.bharatseo.bcreport;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Alarms are lost on reboot / app update / time change – set them again. */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        AlarmScheduler.scheduleAll(context.getApplicationContext());
        PollWorker.schedule(context.getApplicationContext());
    }
}
