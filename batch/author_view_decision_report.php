<?php
// author_view_decision_report.php -- CSV report: can authors see decisions?

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(AuthorViewDecisionReport_Batch::run_args($argv));
}

class AuthorViewDecisionReport_Batch {
    /** @var Conf */
    private $conf;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
    }

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting author-view-decision report generation...\n");

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
        $base_dir = dirname(__DIR__) . "/logs/author_view_decision";
        if (!is_dir($base_dir)) {
            @mkdir($base_dir, 0777, true);
        }

        $root_user = $this->conf->root_user();

        // Decision visibility depends on Conf::setting("seedec") values; iterate common modes
        $modes = [
            ["filename" => "never", "seedec" => 0],
            ["filename" => "yes", "seedec" => Conf::SEEDEC_REV]
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
                    // Clear overrides; simulate UI force for chairs only for conflicts, not author rights
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
                        // Permit ONLY when a decision exists and visibility permits it,
                        // for both authors and admins (to match your expectation that
                        // only decided papers appear as Permit).
                        $decision_set = (int) ($prow->outcome ?? 0) !== 0;
                        $is_author = $uf->act_author_view($prow);
                        $is_admin = $uf->can_administer($prow);
                        $allowed = $is_admin
                            || ($is_author && $decision_set && $uf->can_view_decision($prow));
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

        fwrite(STDOUT, "Author view decision reports complete.\n");
        return 0;
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("Generate CSVs for whether authors can view decisions, across seedec modes.")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new AuthorViewDecisionReport_Batch($conf->root_user()))->run();
    }
}


