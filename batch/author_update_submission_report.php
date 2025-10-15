<?php
// author_update_submission_report.php -- CSV reports for author submission update policies

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(AuthorUpdateSubmissionReport_Batch::run_args($argv));
}

class AuthorUpdateSubmissionReport_Batch {
    /** @var Conf */
    private $conf;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
    }

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting author update submission report generation...\n");

        // Collect users
        $user_ids = [];
        $rs = $this->conf->qe("SELECT contactId FROM ContactInfo");
        while (($row = $rs->fetch_object())) {
            $user_ids[] = (int) $row->contactId;
        }
        Dbl::free($rs);

        // Collect papers
        $papers = [];
        $prs = $this->conf->qe("SELECT * FROM Paper");
        while (($prow = PaperInfo::fetch($prs, null, $this->conf))) {
            $papers[] = $prow;
        }
        Dbl::free($prs);

        // Output directory
        $base_dir = dirname(__DIR__) . "/logs/author_update_submission";
        if (!is_dir($base_dir)) {
            @mkdir($base_dir, 0777, true);
        }

        $root_user = $this->conf->root_user();

        // Two modes: allow updates (0) vs freeze submissions (1)
        $modes = [
            ["filename" => "allow_updates", "sub_freeze" => 0],
            ["filename" => "freeze_submissions", "sub_freeze" => 1]
        ];

        foreach ($modes as $mode) {
            $csv_path = $base_dir . "/" . $mode["filename"] . ".csv";
            $fh = fopen($csv_path, "w");
            if ($fh === false) {
                fwrite(STDERR, "Cannot open $csv_path for writing\n");
                continue;
            }
            fputcsv($fh, ["User", "Resource", "Decision"], ",");

            // Set setting in-memory and refresh derived settings
            $old_subfreeze = $this->conf->settings["sub_freeze"] ?? null;
            $this->conf->settings["sub_freeze"] = $mode["sub_freeze"];
            // Force recompute of settings
            $this->conf->refresh_settings();
            Contact::update_rights();

            try {
                foreach ($user_ids as $uid) {
                    $u = $this->conf->user_by_id($uid);
                    if (!$u) {
                        continue;
                    }
                    // Clear overrides; then simulate UI force for chairs
                    if (method_exists($u, 'with_overrides')) {
                        $u = $u->with_overrides(0);
                    } else if (method_exists($u, 'set_overrides')) {
                        $u->set_overrides(0);
                    } else if (property_exists($u, 'overrides')) {
                        $u->overrides = 0;
                    }
                    $uf = $u;
                    if ($u->is_manager()) {
                        if (method_exists($u, 'with_overrides')) {
                            $uf = $u->with_overrides(Contact::OVERRIDE_CONFLICT);
                        } else if (method_exists($u, 'set_overrides')) {
                            $u->set_overrides(Contact::OVERRIDE_CONFLICT);
                            $uf = $u;
                        }
                    }
                    $this->conf->user = $uf;

                    foreach ($papers as $prow) {
                        // Use the actual HotCRP permission function
                        $allowed = $uf->can_edit_paper($prow);
                        
                        fputcsv($fh, [
                            "u" . $uf->contactId,
                            "p" . $prow->paperId,
                            $allowed ? "Permit" : "Deny"
                        ], ",");
                    }
                }
            } finally {
                if ($old_subfreeze === null) {
                    unset($this->conf->settings["sub_freeze"]);
                } else {
                    $this->conf->settings["sub_freeze"] = $old_subfreeze;
                }
                $this->conf->refresh_settings();
                Contact::update_rights();
                $this->conf->user = $root_user;
            }

            fclose($fh);
            fwrite(STDOUT, "Wrote: $csv_path\n");
        }

        fwrite(STDOUT, "Author update submission reports complete.\n");
        return 0;
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("Generate CSVs for author submission update policies (allow updates vs freeze submissions).")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new AuthorUpdateSubmissionReport_Batch($conf->root_user()))->run();
    }
}
