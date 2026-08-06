<?php
// Serve the login page directly instead of redirecting to it. The redirect
// was the last network round-trip Lighthouse was penalizing on "/" — login.php
// has no path-dependent logic (its own checks use HTTP_HOST, not the request
// path), so including it here renders identical output with zero redirects.
require __DIR__ . '/login.php';
?>