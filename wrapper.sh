#!/bin/bash
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)  # Get the directory of the wrapper script

# Ensure the directory exists
mkdir -p "$SCRIPT_DIR/../../assets/emailpipe"

# Log environment variables
env > "$SCRIPT_DIR/../../assets/emailpipe/env.log"

# Retrieve the envelope recipient from Exim's environment variables
RECIPIENT="${LOCAL_PART}@${DOMAIN}"

# Pass the recipient and email content to your PHP script
"$SCRIPT_DIR"/emailpipe.php "$RECIPIENT" "$SENDER"
