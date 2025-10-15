<?php
// generateAccessLogs.php -- HotCRP command-line log generation script
// Custom script for REBAC analysis.

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

    /**
     * Clear any temporary/chair/admin overrides from a Contact object.
     * Works across old and newer HotCRP builds.
     * @param Contact $u
     * @return Contact
     */
    private function clear_overrides(Contact $u) {
        if (method_exists($u, 'with_overrides')) {
            return $u->with_overrides(0);   // immutable, returns a copy
        }
        if (method_exists($u, 'set_overrides')) {
            $u->set_overrides(0);           // mutable
            return $u;
        }
        if (property_exists($u, 'overrides')) {
            $u->overrides = 0;              // very old builds
        }
        return $u;
    }

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting log generation...\n");

        // --- 1) Gather canonical user IDs
        $user_ids = [];
        $rs = $this->conf->qe("SELECT contactId FROM ContactInfo");
        while (($row = $rs->fetch_object())) {
            $user_ids[] = (int) $row->contactId;
        }
        Dbl::free($rs);
        fwrite(STDOUT, "Found " . count($user_ids) . " users to test.\n");

        // --- 2) Gather all papers (PaperInfo objects)
        $papers = [];
        $prs = $this->conf->qe("SELECT * FROM Paper");
        while (($prow = PaperInfo::fetch($prs, null, $this->conf))) {
            $papers[] = $prow;
        }
        Dbl::free($prs);
        fwrite(STDOUT, "Found " . count($papers) . " papers to check against.\n");

        // Keep root to restore later
        $root_user = $this->conf->root_user();

        $checks_run = 0;

        try {
            foreach ($user_ids as $uid) {
                // Load a fully-initialized Contact (roles/flags populated)
                $u = $this->conf->user_by_id($uid);
                if (!$u) {
                    continue;
                }

                // IMPORTANT: remove any temporary/chair overrides (do NOT touch roles)
                $u = $this->clear_overrides($u);

                // If your logger reads $Conf->user, set it (your build has no set_user()).
                $this->conf->user = $u;

                // Optional debug (comment out if too chatty)
                // fwrite(STDOUT, sprintf("User %d roles=%d\n", $u->contactId, (int)($u->roles ?? 0)));

                foreach ($papers as $prow) {
                    // Trigger permission checks you’ve instrumented
                    $u->can_view_paper($prow);
                    $u->can_view_authors($prow);
                    //$u->can_pc_view_incomplete_paper($prow);
                    $u->can_view_review_identity($prow);

                    // For each paper, check all its reviews
                    $rrs = $this->conf->qe("SELECT * FROM PaperReview WHERE paperId=?", $prow->paperId);
                    while (($rinfo = ReviewInfo::fetch($rrs, null, $this->conf))) {
                        $u->can_view_review($prow, $rinfo);
                    }
                    Dbl::free($rrs);

                    $checks_run++; // one “user × paper” permission set executed
                }
            }
        } finally {
            // Always restore the root user context
            $this->conf->user = $root_user;
        }

        fwrite(STDOUT, "Log generation complete. {$checks_run} user-paper permission sets were logged.\n");
        fwrite(STDOUT, "You can now find the data in the 'logs/access_log.csv' file.\n");
        return 0;
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("Generate access control logs by simulating checks for all users and papers.")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new GenerateLogs_Batch($conf->root_user()))->run();
    }
}
