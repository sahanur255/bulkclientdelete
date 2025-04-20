<?php
/**
 * WHMCS Addon Module: Bulk Client Delete
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Module configuration
 */
function bulkclientdelete_config() {
    return [
        "name" => "Bulk Client Delete",
        "description" => "Allows admins to bulk delete client accounts and their user records.",
        "version" => "1.2",
        "author" => "SAHANUR MONDAL",
        "language" => "english",
    ];
}

/**
 * Module activation
 */
function bulkclientdelete_activate() {
    return [
        'status' => 'success',
        'description' => 'Bulk Client Delete Module Activated',
    ];
}

/**
 * Module deactivation
 */
function bulkclientdelete_deactivate() {
    return [
        'status' => 'success',
        'description' => 'Bulk Client Delete Module Deactivated',
    ];
}

/**
 * Output function: renders the addon page
 */
function bulkclientdelete_output($vars) {
    $adminUsername = $vars['adminuser'];
    echo '<h2>Bulk Client Delete</h2>';

    // Display warning alert
    echo '<div class="alert alert-warning">';
    echo '<strong>Warning:</strong> Deleting clients and users is irreversible. Please select carefully.';
    echo '</div>';

    // Handle form submission
    if (isset($_POST['delete_submit']) && !empty($_POST['clients'])) {
        $deletedClients = 0;
        $deletedUsers = 0;
        foreach ($_POST['clients'] as $clientId) {
            $clientId = (int)$clientId;

            // Delete client via API
            $apiResult = localAPI('DeleteClient', ['clientid' => $clientId], $adminUsername);
            if (isset($apiResult['result']) && $apiResult['result'] === 'success') {
                $deletedClients++;

                // Also delete any associated user records
                try {
                    $count = Capsule::table('tblusers')
                        ->where('clientid', $clientId)
                        ->delete();
                    $deletedUsers += $count;
                } catch (\Exception $e) {
                    // Log or ignore failures
                }
            }
        }

        // Display summary
        echo '<div class="successbox">';
        echo "Deleted {$deletedClients} client(s) and {$deletedUsers} user record(s).";
        echo '</div>';
    }

    // Fetch clients
    $clients = Capsule::table('tblclients')
        ->select('id', 'firstname', 'lastname', 'email')
        ->orderBy('id', 'desc')
        ->get();

    // Render form
    echo '<form method="post" action="">';
    echo '<button type="submit" name="delete_submit" class="btn btn-danger" ' .
         'onclick="return confirm(\'Are you absolutely sure you want to delete the selected clients and all associated users? ' .
                  'This action cannot be undone.\')">';
    echo 'Delete Selected Clients</button>';
    echo '<table class="table table-striped" style="margin-top:15px;">';
    echo '<thead><tr>';
    echo '<th><input type="checkbox" id="select_all" /></th>';
    echo '<th>Client ID</th><th>Name</th><th>Email</th>';
    echo '</tr></thead><tbody>';
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

    // JavaScript for "Select All" functionality
    echo <<<JS
<script type="text/javascript">
    document.getElementById('select_all').addEventListener('change', function() {
        var checked = this.checked;
        document.querySelectorAll('.client_checkbox').forEach(function(cb) {
            cb.checked = checked;
        });
    });
</script>
JS;
}
?>
