<?php
// pc_view_pdf_report.php -- CSV reports for PC viewing submitted PDFs

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(PCViewPdfReport_Batch::run_args($argv));
}

class PCViewPdfReport_Batch {
    /** @var Conf */
    private $conf;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
    }

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting PC view-PDF report generation...\n");

        // Collect users
        $user_ids = [];
        $rs = $this->conf->qe("SELECT contactId FROM ContactInfo");
        while (($row = $rs->fetch_object())) {
            $user_ids[] = (int) $row->contactId;
        }
        Dbl::free($rs);

        // Collect papers (include submitted and incomplete so we log Deny for incompletes)
        $papers = [];
        $prs = $this->conf->qe("SELECT * FROM Paper");
        while (($prow = PaperInfo::fetch($prs, null, $this->conf))) {
            $papers[] = $prow;
        }
        Dbl::free($prs);

        // Output directory
        $base_dir = dirname(__DIR__) . "/logs/pc_view_pdf";
        if (!is_dir($base_dir)) {
            @mkdir($base_dir, 0777, true);
        }

        $root_user = $this->conf->root_user();

        // Two modes: unchecked (0) vs checked (1)
        $modes = [
            ["filename" => "unchecked", "pc_seeallpdf" => 0],
            ["filename" => "checked", "pc_seeallpdf" => 1]
        ];

        foreach ($modes as $mode) {
            $csv_path = $base_dir . "/" . $mode["filename"] . ".csv";
            $fh = fopen($csv_path, "w");
            if ($fh === false) {
                fwrite(STDERR, "Cannot open $csv_path for writing\n");
                continue;
            }
            fputcsv($fh, ["User", "Resource", "Decision"], ",");

            // Set setting in-memory and refresh derived round flags/permbits
            $old_pcseepdf = $this->conf->settings["pc_seeallpdf"] ?? null;
            $this->conf->settings["pc_seeallpdf"] = $mode["pc_seeallpdf"];
            // Force recompute of round/permbits
            $this->conf->refresh_settings(); // call exists in HotCRP to recompute
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
                        // Use system permission end-to-end for PDF viewing
                        $allowed = $uf->can_view_paper($prow, true);
                        fputcsv($fh, [
                            "u" . $uf->contactId,
                            "p" . $prow->paperId,
                            $allowed ? "Permit" : "Deny"
                        ], ",");
                    }
                }
            } finally {
                if ($old_pcseepdf === null) {
                    unset($this->conf->settings["pc_seeallpdf"]);
                } else {
                    $this->conf->settings["pc_seeallpdf"] = $old_pcseepdf;
                }
                $this->conf->refresh_settings();
                Contact::update_rights();
                $this->conf->user = $root_user;
            }

            fclose($fh);
            fwrite(STDOUT, "Wrote: $csv_path\n");
        }

        fwrite(STDOUT, "PC view-PDF reports complete.\n");
        return 0;
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("Generate CSVs for the 'PC can view submitted PDFs' option (checked/unchecked).")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new PCViewPdfReport_Batch($conf->root_user()))->run();
    }
}
