#!/bin/bash
COMMAND="phpunit -c ./phpunit.xml"
$COMMAND
fswatch -o ./**/*.php | xargs -n1 -I{} $COMMAND
