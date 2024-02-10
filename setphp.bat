@echo off
setlocal enabledelayedexpansion

rem This script will replace any existing PHP path in the system PATH variable with a new one.
rem If no PHP path is present, it will add the new PHP path.
rem Run this script as an administrator.

rem Set your new PHP path here
set "new_php_path=C:\wamp64\bin\php\php8.2.9"

rem Initialize a variable to keep track of PHP path found
set "php_found=0"

rem Parse the existing PATH and replace PHP path
set "new_path="
for %%i in ("%path:;=" "%") do (
    set "segment=%%~i"
    if "!segment:php\=!" neq "!segment!" (
        rem PHP path found, replace it
        if !php_found! equ 0 (
            set "segment=%new_php_path%"
            set "php_found=1"
        ) else (
            rem Skip this segment as PHP path is already replaced
            set "segment="
        )
    )
    if "!segment!" neq "" (
        if defined new_path (
            set "new_path=!new_path!;!segment!"
        ) else (
            set "new_path=!segment!"
        )
    )
)

rem If no PHP path was found, append the new PHP path
if !php_found! equ 0 (
    set "new_path=%path%;%new_php_path%"
)

rem Apply the new PATH
setx path "!new_path!" /m

echo New PATH set %new_php_path%