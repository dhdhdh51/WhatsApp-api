package site.bharatseo.bcreport;

import android.app.admin.DeviceAdminReceiver;
import android.content.Context;
import android.content.Intent;

/** Receiver selected by Android Enterprise QR / ADB provisioning for organization-managed devices. */
public class MdmAdminReceiver extends DeviceAdminReceiver {
    @Override
    public void onEnabled(Context context, Intent intent) {
        Mdm.enforce(context);
    }

    @Override
    public void onProfileProvisioningComplete(Context context, Intent intent) {
        Mdm.enforce(context);
    }
}
