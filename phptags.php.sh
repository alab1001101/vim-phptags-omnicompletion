#!/bin/bash

# SQLite3 database path
db_path="$HOME/.vim/phptags"

# SQLite3 database filename
db_file="phptags.sqlite"

# Applications full path to root
app_root="/var/www/CMS/alab"

# Applications library directory relative to Applications root
app_libs="library"

# Applications public directory relative to Applications root
app_publ="public"

# Applications public entry point for reflection
app_boot="index.phptags.php"

# Wheter or not to tag Zend Framework library and PHP Core
tag_zend=0

tag_excl="$app_publ:diffs:ZF"


function arg()
{
    args[${#args[@]}]="$1=$2"
	eval "$1"=\"$2\"
}

function scriptdir()
{
    script="$0"

    if [ "${script:0:1}" != "/" ] ; then
        script=$(readlink -f "$PWD/$script")
    elif [ -L "$script" ] ; then
        script=$(readlink -f "$script")
    fi
    echo -n $(dirname "$script")
    return 0
}

if [ ! -d "$db_path" ] ; then
    mkdir "$db_path"
fi

arg "sqlFile" "$db_path/$db_file";
arg "appBoot" "$app_root/$app_publ/$app_boot"

SCRIPTDIR=$(scriptdir)
ERR_LOG="$SCRIPTDIR/err.log"

if [ -f "$ERR_LOG" ] ; then
    rm "$ERR_LOG"
fi

if [ 1 -ne $tag_zend ] ; then
    if [ "$tag_excl" != "" ] ; then
        tag_excl+=":"
    fi
    tag_excl+="Zend"
fi

args="$(echo ${args[@]})"
args="${args// /&}"

tmp=""
depth=1
include="-regex .*\.php"
exclude="-not -path */${tag_excl//://* -not -path */}/*"

while
    tmp=$(find -L "$app_root" -mindepth $depth -maxdepth $depth $include $exclude | xargs)
    [ "$tmp" != ""  -o $depth -lt 5 ]
do
    ((depth++))
    for file in $tmp ; do
        echo Processing: $file
        php -f "$SCRIPTDIR/phptags.php" -- "file=$file&$args" >> "$ERR_LOG" 2>&1
    done
done

if [ -f "$ERR_LOG" -a ! -s "$ERR_LOG" ] ; then
	rm "$ERR_LOG"
fi

exit 0
