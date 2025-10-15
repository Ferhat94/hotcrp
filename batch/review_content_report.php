<?php
// review_content_report.php -- Generate per-policy CSV reports for review content visibility

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(ReviewContentReport_Batch::run_args($argv));
}

class ReviewContentReport_Batch {
    /** @var Conf */
    private $conf;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
    }

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting review content report generation...\n");

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
        $base_dir = dirname(__DIR__) . "/logs/view_reviewer_content";
        if (!is_dir($base_dir)) {
            @mkdir($base_dir, 0777, true);
        }

        $root_user = $this->conf->root_user();

        // Map the four policy options to Conf constants
        $modes = [
            ["filename" => "yes", "pc" => Conf::VIEWREV_ALWAYS, "ext" => Conf::VIEWREV_ALWAYS],
            ["filename" => "unless_incomplete", "pc" => Conf::VIEWREV_UNLESSINCOMPLETE, "ext" => Conf::VIEWREV_UNLESSINCOMPLETE],
            ["filename" => "unless_any_incomplete", "pc" => Conf::VIEWREV_UNLESSANYINCOMPLETE, "ext" => Conf::VIEWREV_UNLESSANYINCOMPLETE],
            ["filename" => "after_review", "pc" => Conf::VIEWREV_AFTERREVIEW, "ext" => Conf::VIEWREV_AFTERREVIEW]
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

            // Override in-memory settings (don't persist)
            $old_pc = $this->conf->settings["viewrev"] ?? null;
            $old_ext = $this->conf->settings["viewrev_ext"] ?? null;
            $this->conf->settings["viewrev"] = $mode["pc"];
            $this->conf->settings["viewrev_ext"] = $mode["ext"];

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
                        // Single, canonical decision per user×paper (no specific review)
                        $result = $uf->can_view_review($prow, null);
                        fputcsv($fh, [
                            "u" . $uf->contactId,
                            "p" . $prow->paperId,
                            $result ? "Permit" : "Deny"
                        ], ",");
                    }
                }
            } finally {
                // restore settings
                if ($old_pc === null) {
                    unset($this->conf->settings["viewrev"]);
                } else {
                    $this->conf->settings["viewrev"] = $old_pc;
                }
                if ($old_ext === null) {
                    unset($this->conf->settings["viewrev_ext"]);
                } else {
                    $this->conf->settings["viewrev_ext"] = $old_ext;
                }
                $this->conf->user = $root_user;
            }

            fclose($fh);
            fwrite(STDOUT, "Wrote: $csv_path\n");
        }

        fwrite(STDOUT, "Review content reports complete.\n");
        return 0;
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("Generate per-policy CSV reports for review content visibility.")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new ReviewContentReport_Batch($conf->root_user()))->run();
    }
}
