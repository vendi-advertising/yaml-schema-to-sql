#!/bin/bash

##see: http://stackoverflow.com/questions/192249/how-do-i-parse-command-line-arguments-in-bash
# Use -gt 1 to consume two arguments per pass in the loop
# Use -gt 0 to consume one or more arguments per pass in the loop

RUN_PHAN=false
RUN_LINT=true
RUN_SEC=true
UPDATE_COMPOSER=false
RUN_PHP_CS=true
while [[ $# -gt 0 ]]
do
    key="$1"

        case $key in

            -g|--group)
                GROUP="$2"
                shift # past argument
            ;;

            --simple)
                RUN_PHP_CS=false
                RUN_LINT=true
                RUN_PHAN=false
                CREATE_DB=false
                UPDATE_COMPOSER=false
                RUN_SEC=false
            ;;

            --no-run-php-cs)
                RUN_PHP_CS=false
            ;;

            --no-lint)
                RUN_LINT=false
            ;;

            --run-phan)
                RUN_PHAN=true
            ;;

            --update-composer)
                UPDATE_COMPOSER=true
            ;;

            *)
                    # unknown option
            ;;
        esac

    shift # past argument or value
done

maybe_run_php_cs()
{
    echo "Maybe running PHP CS Fixer...";
    if [ "$RUN_PHP_CS" = true ]; then
    {
        echo "running...";

        vendor/bin/php-cs-fixer fix
        if [ $? -ne 0 ]; then
        {
            echo "Error with composer... exiting";
            exit 1;
        }
        fi
    }
    else
        echo "skipping";
    fi

    printf "\n";
}

maybe_update_composer()
{
    echo "Maybe updating composer...";
    if [ "$UPDATE_COMPOSER" = true ]; then
    {
        echo "running...";
        composer update
        if [ $? -ne 0 ]; then
        {
            echo "Error with composer... exiting";
            exit 1;
        }
        fi
    }
    else
        echo "skipping";
    fi

    printf "\n";
}

maybe_run_linter()
{
    echo "Maybe running linter...";
    if [ "$RUN_LINT" = true ]; then
    {
        echo "running...";
        ./vendor/bin/parallel-lint --exclude vendor/ .
        if [ $? -ne 0 ]; then
        {
            echo "Error with PHP linter... exiting";
            exit 1;
        }
        fi
    }
    else
        echo "skipping";
    fi

    printf "\n";
}

maybe_run_phan()
{
    echo "Maybe running phan...";
    if [ "$RUN_PHAN" = true ]; then
    {
        echo "running...";
        ./vendor/bin/phan .
        if [ $? -ne 0 ]; then
        {
            echo "Error with PHP PHAN... exiting";
            exit 1;
        }
        fi
    }
    else
        echo "skipping";
    fi

    printf "\n";
}

maybe_run_security_check()
{
    echo "Maybe running security check...";
    if [ "$RUN_SEC" = true ]; then
    {
        echo "running...";
        vendor/bin/security-checker security:check --end-point=http://security.sensiolabs.org/check_lock --timeout=30 ./composer.lock
        if [ $? -ne 0 ]; then
        {
            echo "Error with security checker... exiting";
            exit 1;
        }
        fi
    }
    else
        echo "skipping";
    fi

    printf "\n";
}

run_php_unit()
{
    echo "Running PHPUnit...";

    if [ -z "$GROUP" ]; then
        ./vendor/bin/phpunit --coverage-html ./tests/logs/coverage/
    else
        ./vendor/bin/phpunit --coverage-html ./tests/logs/coverage/ --group $GROUP
    fi
}

if [ ! -d './vendor' ]; then
    composer update
fi

maybe_update_composer;
maybe_run_linter;
maybe_run_php_cs;
maybe_run_phan;
maybe_run_security_check;

#run_php_unit;
