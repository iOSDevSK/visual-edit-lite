#!/bin/bash
# Assumes page 417 currently has a saved responsive override (set by step e2).
echo "--- before deactivation"; curl -s "http://localhost:1111/ve-qa-blocks/?nc=$RANDOM" | grep -c "data-ve-responsive\|@media (max-width:600px)" 
docker exec wordpress-1111-wordpress-1 php -r 'error_reporting(0); require "/var/www/html/wp-load.php"; deactivate_plugins("visual-edit-lite/visual-edit-lite.php"); echo "active=", is_plugin_active("visual-edit-lite/visual-edit-lite.php") ? 1 : 0, "\n";'
echo "--- after deactivation"; H=$(curl -s -o /tmp/deact.html -w "%{http_code}" "http://localhost:1111/ve-qa-blocks/?nc=$RANDOM"); echo "http=$H"; grep -c "QA heading" /tmp/deact.html; grep -c "data-ve-responsive\|max-width:600px){[^}]*22px" /tmp/deact.html; grep -c "claraVe" /tmp/deact.html
