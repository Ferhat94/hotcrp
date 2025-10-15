<?php
// reviewer_view_decision_report.php -- CSV report: can reviewers see decisions?

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(ReviewerViewDecisionReport_Batch::run_args($argv));
}

class ReviewerViewDecisionReport_Batch {
    /** @var Conf */
    private $conf;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
    }

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting reviewer-view-decision report generation...\n");

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
        $base_dir = dirname(__DIR__) . "/logs/reviewer_view_decision";
        if (!is_dir($base_dir)) {
            @mkdir($base_dir, 0777, true);
        }

        $root_user = $this->conf->root_user();

        // Three reviewer decision visibility modes
        $modes = [
            ["filename" => "no", "seedec" => 0],
            ["filename" => "yes", "seedec" => Conf::SEEDEC_REV],
            ["filename" => "yes_unless_conflict", "seedec" => Conf::SEEDEC_NCREV]
        ];

        foreach ($modes as $mode) {
            $csv_path = $base_dir . "/" . $mode["filename"] . ".csv";
            $fh = fopen($csv_path, "w");
            if ($fh === false) {
                fwrite(STDERR, "Cannot open $csv_path for writing\n");
                continue;
            }
            fputcsv($fh, ["User", "Resource", "Decision"], ",");

            $old_seedec = $this->conf->settings["seedec"] ?? null;
            $this->conf->settings["seedec"] = $mode["seedec"];
            $this->conf->refresh_settings();
            Contact::update_rights();

            try {
                foreach ($user_ids as $uid) {
                    $u = $this->conf->user_by_id($uid);
                    if (!$u) {
                        continue;
                    }
                    // Clear overrides; simulate chair force for conflicts only
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
                        // Require a decision to be set for reviewers; admins stay allowed.
                        $decision_set = (int) ($prow->outcome ?? 0) !== 0;
                        $is_admin = $uf->can_administer($prow);
                        $is_reviewer = $prow->has_reviewer($uf);
                        $allowed = $is_admin
                            || ($decision_set && $is_reviewer && $uf->can_view_decision($prow));
                        fputcsv($fh, [
                            "u" . $uf->contactId,
                            "p" . $prow->paperId,
                            $allowed ? "Permit" : "Deny"
                        ], ",");
                    }
                }
            } finally {
                if ($old_seedec === null) {
                    unset($this->conf->settings["seedec"]);
                } else {
                    $this->conf->settings["seedec"] = $old_seedec;
                }
                $this->conf->refresh_settings();
                Contact::update_rights();
                $this->conf->user = $root_user;
            }

            fclose($fh);
            fwrite(STDOUT, "Wrote: $csv_path\n");
        }

        fwrite(STDOUT, "Reviewer view decision reports complete.\n");
        return 0;
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("Generate CSVs for whether reviewers can view decisions, across modes.")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new ReviewerViewDecisionReport_Batch($conf->root_user()))->run();
    }
}


