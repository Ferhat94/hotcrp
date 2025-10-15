<?php
// submission_anonymity_report.php -- Generate per-policy CSVs for submission anonymity (author visibility)

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(SubmissionAnonymityReport_Batch::run_args($argv));
}

class SubmissionAnonymityReport_Batch {
    /** @var Conf */
    private $conf;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
    }

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting submission anonymity report generation...\n");

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

        // Prepare output directory
        $base_dir = dirname(__DIR__) . "/logs/submission_anonymity";
        if (!is_dir($base_dir)) {
            @mkdir($base_dir, 0777, true);
        }

        $root_user = $this->conf->root_user();

        // Modes mapped to Conf blindness constants
        $modes = [
            ["filename" => "yes", "blind" => Conf::BLIND_ALWAYS],           // submissions are anonymous
            ["filename" => "no", "blind" => Conf::BLIND_NEVER],              // author names visible
            ["filename" => "until_review", "blind" => Conf::BLIND_ALWAYS],   // anonymous until reviewer submits review (handled in script)
            ["filename" => "depends", "blind" => Conf::BLIND_OPTIONAL]       // authors decide
        ];

        foreach ($modes as $mode) {
            $csv_path = $base_dir . "/" . $mode["filename"] . ".csv";
            $fh = fopen($csv_path, "w");
            if ($fh === false) {
                fwrite(STDERR, "Cannot open $csv_path for writing\n");
                continue;
            }
            // header
            fputcsv($fh, ["User", "Resource", "Decision"], ",");

            // Override in-memory setting (do not persist)
            $old_blind = $this->conf->settings["sub_blind"] ?? null;
            $this->conf->settings["sub_blind"] = $mode["blind"];
            // invalidate cached permission computations
            Contact::update_rights();

            try {
                foreach ($user_ids as $uid) {
                    $u = $this->conf->user_by_id($uid);
                    if (!$u) {
                        continue;
                    }
                    // Ensure clean overrides
                    if (method_exists($u, 'with_overrides')) {
                        $u = $u->with_overrides(0);
                    } else if (method_exists($u, 'set_overrides')) {
                        $u->set_overrides(0);
                    } else if (property_exists($u, 'overrides')) {
                        $u->overrides = 0;
                    }
                    // For chairs/managers, simulate UI force (admin override)
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
                        // Base decision from core logic
                        $allowed = $uf->can_view_authors($prow);

                        // For "until_review": permit if the user has submitted a review on this paper
                        if ($mode["filename"] === "until_review" && !$allowed) {
                            // quick check: does user have a submitted review on this paper?
                            $has_submitted_review = false;
                            $rrs = $this->conf->qe("SELECT reviewSubmitted FROM PaperReview WHERE paperId=? AND contactId=?", $prow->paperId, $uf->contactId);
                            while (($r = $rrs->fetch_row())) {
                                if ((int)($r[0] ?? 0) > 0) {
                                    $has_submitted_review = true;
                                    break;
                                }
                            }
                            Dbl::free($rrs);
                            if ($has_submitted_review) {
                                $allowed = true;
                            }
                        }

                        fputcsv($fh, [
                            "u" . $uf->contactId,
                            "p" . $prow->paperId,
                            $allowed ? "Permit" : "Deny"
                        ], ",");
                    }
                }
            } finally {
                // restore settings
                if ($old_blind === null) {
                    unset($this->conf->settings["sub_blind"]);
                } else {
                    $this->conf->settings["sub_blind"] = $old_blind;
                }
                Contact::update_rights();
                $this->conf->user = $root_user;
            }

            fclose($fh);
            fwrite(STDOUT, "Wrote: $csv_path\n");
        }

        fwrite(STDOUT, "Submission anonymity reports complete.\n");
        return 0;
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("Generate per-policy CSV reports for submission anonymity (author visibility).")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new SubmissionAnonymityReport_Batch($conf->root_user()))->run();
    }
}


