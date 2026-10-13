package site.bharatseo.bcreport;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** Alarms are lost on reboot / app update / time change – set them again. */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        Context c = context.getApplicationContext();
        AlarmScheduler.scheduleAll(c);
        PollWorker.schedule(c);
        Mdm.enforce(c);
    }
}
