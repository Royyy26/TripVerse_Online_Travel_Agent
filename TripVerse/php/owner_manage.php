<?php
// Owner creation/listing now lives in the "Manage Owners" tab of supplier_approvals.php
// (this page used to be unreachable from any nav link, and duplicated that page's design).
header("Location: supplier_approvals.php");
exit;
