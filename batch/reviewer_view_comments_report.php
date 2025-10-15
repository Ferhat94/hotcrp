<?php
// reviewer_view_comments_report.php -- CSV reports for reviewer comment visibility

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(ReviewerViewCommentsReport_Batch::run_args($argv));
}

class ReviewerViewCommentsReport_Batch {
    /** @var Conf */
    private $conf;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
    }

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting reviewer view comments report generation...\n");

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
        $base_dir = dirname(__DIR__) . "/logs/reviewer_view_comments";
        if (!is_dir($base_dir)) {
            @mkdir($base_dir, 0777, true);
        }

        $root_user = $this->conf->root_user();

        // Two modes: only when can see reviewer names (0) vs always (1)
        $modes = [
            ["filename" => "only_when_see_reviewer_names", "cmt_revid" => 0],
            ["filename" => "yes", "cmt_revid" => 1]
        ];

        foreach ($modes as $mode) {
            $csv_path = $base_dir . "/" . $mode["filename"] . ".csv";
            fwrite(STDOUT, "Processing mode: " . $mode["filename"] . " (cmt_revid=" . $mode["cmt_revid"] . ")\n");
            fwrite(STDOUT, "Current reviewer identity visibility: " . ($this->conf->settings["viewrevid"] ?? "not set") . "\n");
            $fh = fopen($csv_path, "w");
            if ($fh === false) {
                fwrite(STDERR, "Cannot open $csv_path for writing\n");
                continue;
            }
            fputcsv($fh, ["User", "Resource", "Decision", "cmt_revid", "viewrevid", "can_view_identity", "is_pc", "is_reviewer"], ",");

            // Set setting in-memory and refresh derived settings
            $old_cmtrevid = $this->conf->settings["cmt_revid"] ?? null;
            $this->conf->settings["cmt_revid"] = $mode["cmt_revid"];
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
                    foreach ($papers as $prow) {
                        // Check if user can view comments on this paper
                        // We'll create a dummy comment to test the permission
                        $dummy_comment = new CommentInfo();
                        $dummy_comment->conf = $this->conf;
                        $dummy_comment->prow = $prow;
                        $dummy_comment->paperId = $prow->paperId;
                        $dummy_comment->commentId = 1;
                        $dummy_comment->contactId = 999; // Use a high ID that's unlikely to be the current user
                        // Use CTVIS_REVIEWER level comment - this is where cmt_revid setting matters
                        $dummy_comment->commentType = CommentInfo::CTVIS_REVIEWER | CommentInfo::CT_TOPIC_PAPER;
                        
                        // Only test if user has some relationship to this paper
                        $is_author = $uf->act_author_view($prow);
                        $is_reviewer = $uf->is_reviewer();
                        $is_pc = $uf->isPC;
                        $is_admin = $uf->can_administer($prow);
                        
                        // Only test comment visibility if user has some role on this paper
                        if ($is_author || $is_reviewer || $is_pc || $is_admin) {
                            // Test if user can view this specific comment
                            $allowed = $uf->can_view_comment($prow, $dummy_comment);
                        } else {
                            $allowed = false; // No role = no access
                        }
                        
                        // Add debug info to understand why cmt_revid isn't making a difference
                        $can_view_identity = $uf->can_view_comment_identity($prow, $dummy_comment);
                        $cmt_revid_setting = $this->conf->setting("cmt_revid");
                        $viewrevid_setting = $this->conf->setting("viewrevid");
                        
                        fputcsv($fh, [
                            "u" . $uf->contactId,
                            "p" . $prow->paperId,
                            $allowed ? "Permit" : "Deny",
                            "cmt_revid=" . ($cmt_revid_setting ?? "null"),
                            "viewrevid=" . ($viewrevid_setting ?? "null"),
                            "can_view_identity=" . ($can_view_identity ? "true" : "false"),
                            "is_pc=" . ($is_pc ? "true" : "false"),
                            "is_reviewer=" . ($is_reviewer ? "true" : "false")
                        ], ",");
                    }
                }
            } finally {
                if ($old_cmtrevid === null) {
                    unset($this->conf->settings["cmt_revid"]);
                } else {
                    $this->conf->settings["cmt_revid"] = $old_cmtrevid;
                }
                $this->conf->refresh_settings();
                Contact::update_rights();
            }

            fclose($fh);
            fwrite(STDOUT, "Wrote: $csv_path\n");
        }

        fwrite(STDOUT, "Reviewer view comments reports complete.\n");
        return 0;
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("Generate CSVs for reviewer comment visibility policies (only when can see reviewer names vs always).")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new ReviewerViewCommentsReport_Batch($conf->root_user()))->run();
    }
}
