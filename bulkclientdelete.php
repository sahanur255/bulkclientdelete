<?php
if (!defined("WHMCS")) die("This file cannot be accessed directly");

use WHMCS\Database\Capsule;

function bulkclientdelete_config() {
    return [
        "name" => "Bulk Client Delete",
        "description" => "Delete multiple clients and all associated users.",
        "version" => "1.6",
        "author" => "Sahanur Mondal",
        "fields" => [
            "license_key" => [
                "FriendlyName" => "License Key",
                "Type" => "text",
                "Size" => "40",
                "Description" => "Enter your license key.",
            ]
        ]
    ];
}

function bulkclientdelete_output($vars) {
    $adminUsername = $vars['adminuser'];
    echo '<h2>Bulk Client Delete</h2>';

    echo '<div class="alert alert-warning">';
    echo '<strong>Warning:</strong> Deleting clients and users is irreversible. Please select carefully.';
    echo '</div>';

    $filters = [
        'groupid' => $_POST['groupid'] ?? '',
        'status' => $_POST['status'] ?? '',
        'created_before' => $_POST['created_before'] ?? '',
        'email_domain' => $_POST['email_domain'] ?? '',
        'country' => $_POST['country'] ?? '',
        'no_orders' => isset($_POST['no_orders']),
        'no_invoices' => isset($_POST['no_invoices']),
    ];

    if (isset($_POST['delete_submit']) && !empty($_POST['clients'])) {
        $deletedClients = 0;
        $deletedUsers = 0;
        $skippedClients = [];

        foreach ($_POST['clients'] as $clientId) {
            $clientId = (int)$clientId;

            $hasActiveOrder = Capsule::table('tblorders')
                ->where('userid', $clientId)
                ->whereIn('status', ['Active', 'Pending', 'Fraud'])
                ->exists();

            $hasInvoices = Capsule::table('tblinvoices')
                ->where('userid', $clientId)
                ->exists();

            if ($hasActiveOrder || $hasInvoices) {
                $client = Capsule::table('tblclients')->where('id', $clientId)->first();
                $clientName = $client ? "{$client->firstname} {$client->lastname}" : 'Unknown';
                $skippedClients[] = "Client Name: {$clientName}, ID: {$clientId}";
                continue;
            }

            $userLinks = Capsule::table('tblusers_clients')
                ->where('client_id', $clientId)
                ->pluck('auth_user_id');

            Capsule::table('tblusers_clients')
                ->where('client_id', $clientId)
                ->delete();

            $apiResult = localAPI('DeleteClient', ['clientid' => $clientId], $adminUsername);
            if (isset($apiResult['result']) && $apiResult['result'] === 'success') {
                $deletedClients++;

                try {
                    foreach ($userLinks as $authUserId) {
                        $stillLinked = Capsule::table('tblusers_clients')
                            ->where('auth_user_id', $authUserId)
                            ->exists();

                        if (!$stillLinked) {
                            Capsule::table('tblusers')->where('id', $authUserId)->delete();
                            $deletedUsers++;
                        }
                    }

                } catch (\Exception $e) {}
            }
        }

        echo '<div class="successbox">';
        echo "Deleted {$deletedClients} client(s) and {$deletedUsers} user record(s).<br>";
        if (!empty($skippedClients)) {
            echo "<strong>Skipped Clients (active orders/invoices):</strong><br>";
            foreach ($skippedClients as $info) {
                echo htmlspecialchars($info) . "<br>";
            }
        }
        echo '</div>';
    }

    $clientGroups = Capsule::table('tblclientgroups')->get();
    $countries = Capsule::table('tblclients')->select('country')->distinct()->get();

    echo '<form method="post" action="">';
    echo '<h4>Filter Clients</h4>';
    echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 15px;">';

    echo '<div><label><strong>Group:</strong><br>';
    echo '<select name="groupid"><option value="">All</option>';
    foreach ($clientGroups as $group) {
        $selected = ($filters['groupid'] == $group->id) ? 'selected' : '';
        echo "<option value='{$group->id}' $selected>" . htmlspecialchars($group->groupname) . "</option>";
    }
    echo '</select></label></div>';

    echo '<div><label><strong>Status:</strong><br>';
    echo '<select name="status"><option value="">All</option>';
    foreach (['Active', 'Inactive', 'Closed'] as $status) {
        $selected = ($filters['status'] == $status) ? 'selected' : '';
        echo "<option value='$status' $selected>$status</option>";
    }
    echo '</select></label></div>';

    echo '<div><label><strong>Created Before:</strong><br>';
    echo '<input type="date" name="created_before" value="' . htmlspecialchars($filters['created_before']) . '">';
    echo '</label></div>';

    echo '<div><label><strong>Email Domain:</strong><br>';
    echo '<input type="text" name="email_domain" placeholder="@example.com" value="' . htmlspecialchars($filters['email_domain']) . '">';
    echo '</label></div>';

    echo '<div><label><strong>Country:</strong><br>';
    echo '<select name="country"><option value="">All</option>';
    foreach ($countries as $c) {
        $selected = ($filters['country'] == $c->country) ? 'selected' : '';
        echo "<option value='{$c->country}' $selected>{$c->country}</option>";
    }
    echo '</select></label></div>';

    echo '<div style="margin-top: 25px;">';
    echo '<label><input type="checkbox" name="no_orders" ' . ($filters['no_orders'] ? 'checked' : '') . '> No Orders</label><br>';
    echo '<label><input type="checkbox" name="no_invoices" ' . ($filters['no_invoices'] ? 'checked' : '') . '> No Invoices</label>';
    echo '</div>';

    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">Filter</button>';
    echo '</form><br>';

    $clientsQuery = Capsule::table('tblclients')->select('id', 'firstname', 'lastname', 'email');

    if ($filters['groupid']) {
        $clientsQuery->where('groupid', $filters['groupid']);
    }
    if ($filters['status']) {
        $clientsQuery->where('status', $filters['status']);
    }
    if ($filters['created_before']) {
        $clientsQuery->where('datecreated', '<', $filters['created_before']);
    }
    if ($filters['email_domain']) {
        $domain = str_replace('@', '', trim($filters['email_domain']));
        $clientsQuery->where('email', 'like', "%@{$domain}");
    }
    if ($filters['country']) {
        $clientsQuery->where('country', $filters['country']);
    }
    if ($filters['no_orders']) {
        $clientsQuery->whereNotExists(function ($query) {
            $query->select(Capsule::raw(1))
                ->from('tblorders')
                ->whereRaw('tblorders.userid = tblclients.id');
        });
    }
    if ($filters['no_invoices']) {
        $clientsQuery->whereNotExists(function ($query) {
            $query->select(Capsule::raw(1))
                ->from('tblinvoices')
                ->whereRaw('tblinvoices.userid = tblclients.id');
        });
    }

    $clients = $clientsQuery->orderBy('id', 'desc')->get();

    echo '<form method="post" action="">';
    echo '<button type="submit" name="delete_submit" class="btn btn-danger" onclick="return confirm(\'Are you absolutely sure you want to delete the selected clients and all associated users? This action cannot be undone.\')">';
    echo 'Delete Selected Clients</button>';

    echo '<table class="table table-striped" style="margin-top:15px;">';
    echo '<thead><tr><th><input type="checkbox" id="select_all" /></th><th>Client ID</th><th>Name</th><th>Email</th></tr></thead><tbody>';
    foreach ($clients as $c) {
        echo '<tr>';
        echo '<td><input type="checkbox" name="clients[]" value="' . (int)$c->id . '" class="client_checkbox" /></td>';
        echo '<td>' . (int)$c->id . '</td>';
        echo '<td>' . htmlspecialchars($c->firstname . ' ' . $c->lastname) . '</td>';
        echo '<td>' . htmlspecialchars($c->email) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</form>';

    echo <<<JS
<script type="text/javascript">
document.getElementById('select_all').addEventListener('change', function() {
    document.querySelectorAll('.client_checkbox').forEach(function(cb) {
        cb.checked = event.target.checked;
    });
});
</script>
JS;
}
