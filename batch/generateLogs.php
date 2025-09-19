<?php
// generatelogs.php -- HotCRP command-line log generation script
// This is a custom script for the REBAC analysis project.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(GenerateLogs_Batch::run_args($argv));
}

class GenerateLogs_Batch {
    /** @var Conf */
    private $conf;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
    }

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting log generation...\n");

        // 1. Fetch all users from the database
        $users_result = $this->conf->qe("SELECT * FROM ContactInfo");
        $users = [];
        while (($user_row = Contact::fetch($users_result, $this->conf))) {
            $users[] = $user_row;
            fwrite(STDOUT, "ContactID of fetched user: {$user_row->contactId}\n");
        }
        Dbl::free($users_result);
        fwrite(STDOUT, "Found " . count($users) . " users to test.\n");

        // 2. Fetch all papers from the database
        $papers_result = $this->conf->qe("SELECT * FROM Paper");
        $papers = [];
        while (($paper_row = PaperInfo::fetch($papers_result, null, $this->conf))) {
            $papers[] = $paper_row;
            fwrite(STDOUT, "PaperID of fetched paper: {$paper_row->paperId}\n");
        }
        Dbl::free($papers_result);
        fwrite(STDOUT, "Found " . count($papers) . " papers to check against.\n");

        // 3. Loop through every combination and trigger our loggers
        $checks_run = 0;
        foreach ($users as $user) {
            // The check is performed on the specific $user object,
            // which correctly uses that user's permissions.
        
            fwrite(STDOUT, "\n--- Processing checks for user: {$user->contactId} ---\n");

            $this->conf->user = $user;
            //$this->conf->set_user($user);
            foreach ($papers as $paper) {
                // Trigger the authorization functions we instrumented in src/contact.php

                if ($user->contactId == 3 && $paper->paperId == 2) {
                    error_log("SCRIPT: Checking User #{$user->contactId} vs Paper #{$paper->paperId}");
                }
                // --- END DEBUGGING ---

                $user->can_view_paper($paper);
                $user->can_view_review_identity($paper);

                // For each paper, check against every review it might have
                $reviews_result = $this->conf->qe("SELECT * FROM PaperReview WHERE paperId=?", $paper->paperId);
                while (($review_row = ReviewInfo::fetch($reviews_result, null, $this->conf))) {
                    $user->can_view_review($paper, $review_row);
                }
                Dbl::free($reviews_result);
                $checks_run++;
            }
        }
        
        fwrite(STDOUT, "Log generation complete. " . ($checks_run * count($users)) . " total permission checks were logged.\n");
        fwrite(STDOUT, "You can now find the data in the 'logs/access_log.csv' file.\n");
        return 0;
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !", "config: !", "help,h !"
        )->helpopt("help")
         ->description("Generate access control logs by simulating checks for all users and papers.")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new GenerateLogs_Batch($conf->root_user()))->run();
    }
}

