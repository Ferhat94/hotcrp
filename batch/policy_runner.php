<?php
// policy_runner.php -- Run OAT policies and collect per-policy logs
//
// PURPOSE: This script implements "Baseline + One-Change-At-a-Time (OAT)" policy testing
// 
// HOW IT WORKS:
// 1. Define 9 permission groups (author_names_hidden_from_reviewers, etc.)
// 2. Infer baseline settings from current HotCRP configuration
// 3. Generate 17 total policies:
//    - 1 baseline policy (all default settings)
//    - 16 single-change policies (change one setting at a time)
// 4. For each policy, test all users against all papers for the relevant permissions
// 5. Generate CSV files showing Permit/Deny decisions for each user-paper combination
// 6. Create policy.json files documenting which settings were used

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(PolicyRunner_Batch::run_args($argv));
}

class PolicyRunner_Batch {
    /** @var Conf */
    private $conf;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
    }

    /** @return int */
    function run() {
        fwrite(STDOUT, "Starting policy runner (OAT) ...\n");

        // STEP 1: Load all the individual report scripts
        // These scripts contain the logic for testing each permission type
        // We need them loaded so we can reference their classes and understand their logic
        require_once(__DIR__ . "/submission_anonymity_report.php");
        require_once(__DIR__ . "/author_update_submission_report.php");
        require_once(__DIR__ . "/author_view_decision_report.php");
        require_once(__DIR__ . "/review_content_report.php");
        require_once(__DIR__ . "/reviewer_identity_report.php");
        require_once(__DIR__ . "/pc_view_incomplete_report.php");
        require_once(__DIR__ . "/pc_view_pdf_report.php");
        require_once(__DIR__ . "/reviewer_view_comments_report.php");
        require_once(__DIR__ . "/reviewer_view_decision_report.php");

        // STEP 2: Define the 9 permission groups and their options
        // Each group represents a different type of permission in HotCRP
        // Each group has multiple options (like "yes", "no", "depends", etc.)
        // The numbers in comments show how many options each group has
        $groups = [
            // key => [log_dir, script_class, options => [label => filename]]
            "author_names_hidden_from_reviewers" => [  // 4 options
                "dir" => "submission_anonymity",        // Directory where original reports are stored
                "class" => "SubmissionAnonymityReport_Batch",  // PHP class that handles this permission
                "options" => [
                    "yes" => "yes.csv",                 // Conf::BLIND_ALWAYS - always hide author names
                    "no" => "no.csv",                   // Conf::BLIND_NEVER - never hide author names  
                    "until_review" => "until_review.csv", // Hide until review is submitted
                    "depends" => "depends.csv"          // Conf::BLIND_OPTIONAL - depends on other settings
                ]
            ],
            "author_update_submission" => [  // 2 options
                "dir" => "author_update_submission",
                "class" => "AuthorUpdateSubmissionReport_Batch",
                "options" => [
                    "allow_updates" => "allow_updates.csv", // sub_freeze=0 - authors can update their submissions
                    "freeze_submissions" => "freeze_submissions.csv" // sub_freeze=1 - authors cannot update
                ]
            ],
            "authors_view_decision" => [  // 2 options
                "dir" => "author_view_decision",
                "class" => "AuthorViewDecisionReport_Batch",
                "options" => [
                    "never" => "never.csv",  // Authors never see decisions
                    "yes" => "yes.csv"       // Authors can see decisions
                ]
            ],
            "can_view_review_content" => [  // 4 options
                "dir" => "view_reviewer_content",
                "class" => "ReviewContentReport_Batch",
                "options" => [
                    "yes" => "yes.csv",                    // Always show review content
                    "unless_incomplete" => "unless_incomplete.csv",  // Show unless review is incomplete
                    "unless_any_incomplete" => "unless_any_incomplete.csv",  // Show unless ANY review is incomplete
                    "after_review" => "after_review.csv"   // Show only after review is submitted
                ]
            ],
            "can_view_reviewer_identity" => [  // 4 options
                "dir" => "reviewer_identity",
                "class" => "ReviewerIdentityReport_Batch",
                "options" => [
                    "yes" => "yes.csv",           // Always show reviewer names
                    "ifassigned" => "ifassigned.csv",  // Show only if assigned to review
                    "afterreview" => "afterreview.csv",  // Show only after review is submitted
                    "no" => "no.csv"              // Never show reviewer names
                ]
            ],
            "pc_can_view_incomplete_submission" => [  // 2 options
                "dir" => "pc_view_incomplete",
                "class" => "PCViewIncompleteReport_Batch",
                "options" => [
                    "unchecked" => "unchecked.csv",  // PC cannot view incomplete submissions
                    "checked" => "checked.csv"       // PC can view incomplete submissions
                ]
            ],
            "pc_can_view_submitted_pdf" => [  // 2 options
                "dir" => "pc_view_pdf",
                "class" => "PCViewPdfReport_Batch",
                "options" => [
                    "unchecked" => "unchecked.csv",  // PC cannot view submitted PDFs
                    "checked" => "checked.csv"       // PC can view submitted PDFs
                ]
            ],
            "reviewer_view_comments" => [  // 2 options
                "dir" => "reviewer_view_comments",
                "class" => "ReviewerViewCommentsReport_Batch",
                "options" => [
                    "only_when_see_reviewer_names" => "only_when_see_reviewer_names.csv",  // Show comments only when reviewer names are visible
                    "yes" => "yes.csv"  // Always show comments
                ]
            ],
            "reviewers_view_decision" => [  // 3 options
                "dir" => "reviewer_view_decision",
                "class" => "ReviewerViewDecisionReport_Batch",
                "options" => [
                    "no" => "no.csv",                    // Reviewers never see decisions
                    "yes" => "yes.csv",                  // Reviewers can see decisions
                    "yes_unless_conflict" => "yes_unless_conflict.csv"  // Reviewers see decisions unless there's a conflict
                ]
            ]
        ];

        // STEP 3: Infer baseline settings from current HotCRP configuration
        // This reads the current settings in the database and maps them to our option labels
        // For example: if sub_blind=1 (Conf::BLIND_ALWAYS), then baseline["author_names_hidden_from_reviewers"] = "yes"
        $baseline = $this->infer_baseline($groups);

        // STEP 4: Build the list of 17 policies using OAT approach
        // Formula: 1 baseline + sum of (number_of_options - 1) for each group
        // (4-1) + (2-1) + (2-1) + (4-1) + (4-1) + (2-1) + (2-1) + (2-1) + (3-1) = 3+1+1+3+3+1+1+1+2 = 16
        // Total: 1 baseline + 16 single-change = 17 policies
        $policies = [];
        $policies[] = $baseline; // Policy 1: baseline (all default settings)
        
        // Generate 16 single-change policies
        // For each group, create one policy for each non-baseline option
        foreach ($groups as $gkey => $ginfo) {
            foreach ($ginfo["options"] as $label => $fn) {
                if ($label === $baseline[$gkey]) {
                    continue; // Skip the baseline option (we already have it)
                }
                // Create a new policy that's identical to baseline except for this one setting
                $p = $baseline;
                $p[$gkey] = $label;  // Change only this one setting
                $policies[] = $p;
            }
        }

        // STEP 5: Generate CSV files and policy manifests for each of the 17 policies
        $policies_dir = getcwd() . "/logs/policies";
        if (!is_dir($policies_dir)) {
            @mkdir($policies_dir, 0777, true);
        }

        $pi = 1;  // Policy counter
        foreach ($policies as $choice_map) {
            // STEP 5a: Generate descriptive folder name for this policy
            if ($pi === 1) {
                $folder_name = "baseline";  // Policy 1 is always the baseline
            } else {
                // For single-change policies, find which setting changed
                $changed_setting = null;
                $changed_option = null;
                foreach ($choice_map as $gkey => $label) {
                    if ($label !== $baseline[$gkey]) {
                        $changed_setting = $gkey;
                        $changed_option = $label;
                        break;  // Only one setting should be different
                    }
                }
                // Create folder name like "author_names_hidden_from_reviewers_no"
                $folder_name = $changed_setting . "_" . $changed_option;
            }

            // Create directory for this policy
            $pdir = $policies_dir . "/" . $folder_name;
            if (!is_dir($pdir)) {
                @mkdir($pdir, 0777, true);
            }

            // STEP 5b: Generate CSV files for this policy
            if ($pi === 1) {
                // Baseline policy: create 9 separate CSV files (one for each permission type)
                // This gives us a complete view of all permissions under baseline settings
                $this->generate_baseline_separate_csvs($choice_map, $groups, $pdir);
            } else {
                // Single-change policy: create 1 CSV file (only for the changed setting)
                // This shows the impact of changing just one setting
                $this->generate_single_change_csv($choice_map, $baseline, $groups, $pdir);
            }

            // STEP 5c: Write policy.json manifest file
            // This documents exactly which settings were used for this policy
            $manifest = [
                "policy_id" => $folder_name,
                "options" => $choice_map,  // Shows all 9 settings and their values
                "meta" => [
                    "timestamp" => date(DATE_ATOM),
                    "git_commit" => trim((string) shell_exec("git rev-parse --short HEAD 2>/dev/null") ?? ""),
                    "hotcrp_version" => HOTCRP_VERSION
                ]
            ];
            file_put_contents($pdir . "/policy.json", json_encode($manifest, JSON_PRETTY_PRINT));

            fwrite(STDOUT, "Wrote policy " . $manifest["policy_id"] . " -> " . $pdir . "\n");
            ++$pi;
        }

        fwrite(STDOUT, "Policy runner complete.\n");
        return 0;
    }

    /** @param array<string,array> $groups
     * @return array<string,string> */
    private function infer_baseline($groups) {
        // PURPOSE: Read current HotCRP settings from database and map them to our option labels
        // 
        // HOW IT WORKS:
        // 1. Read each setting from $this->conf->settings (the current database configuration)
        // 2. Map the numeric/boolean values to our descriptive labels ("yes", "no", "depends", etc.)
        // 3. Return an array showing what the baseline policy should be
        //
        // EXAMPLE: If sub_blind=1 (Conf::BLIND_ALWAYS), then baseline["author_names_hidden_from_reviewers"] = "yes"
        
        $c = $this->conf;
        $baseline = [];

        // 1) submission anonymity (sub_blind) - controls whether author names are hidden from reviewers
        $sb = (int) ($c->settings["sub_blind"] ?? 0);
        if ($sb === Conf::BLIND_ALWAYS) {  // sub_blind = 1
            $baseline["author_names_hidden_from_reviewers"] = "yes";
        } else if ($sb === Conf::BLIND_NEVER) {  // sub_blind = 0
            $baseline["author_names_hidden_from_reviewers"] = "no";
        } else if ($sb === Conf::BLIND_OPTIONAL) {  // sub_blind = 2
            $baseline["author_names_hidden_from_reviewers"] = "depends";
        } else {
            // Fallback: use first available option if setting is unknown
            $baseline["author_names_hidden_from_reviewers"] = array_key_first($groups["author_names_hidden_from_reviewers"]["options"]);
        }

        // 2) author_update_submission (sub_freeze) - controls whether authors can update their submissions
        $baseline["author_update_submission"] = ((int) ($c->settings["sub_freeze"] ?? 0)) > 0 ? "freeze_submissions" : "allow_updates";

        // 3) authors_view_decision (seedec) - controls whether authors can see decisions
        // Note: seedec controls both authors and reviewers, we approximate author access with seedec>0
        $sd = (int) ($c->settings["seedec"] ?? 0);
        $baseline["authors_view_decision"] = $sd > 0 ? "yes" : "never";

        // 4) review content visibility (viewrev/viewrev_ext) – map PC setting only
        $vr = (int) ($c->settings["viewrev"] ?? 0);
        if ($vr === Conf::VIEWREV_ALWAYS) {
            $baseline["can_view_review_content"] = "yes";
        } else if ($vr === Conf::VIEWREV_UNLESSINCOMPLETE) {
            $baseline["can_view_review_content"] = "unless_incomplete";
        } else if ($vr === Conf::VIEWREV_UNLESSANYINCOMPLETE) {
            $baseline["can_view_review_content"] = "unless_any_incomplete";
        } else if ($vr === Conf::VIEWREV_AFTERREVIEW) {
            $baseline["can_view_review_content"] = "after_review";
        } else {
            $baseline["can_view_review_content"] = array_key_first($groups["can_view_review_content"]["options"]);
        }

        // 5) reviewer identity visibility (viewrevid)
        $vri = (int) ($c->settings["viewrevid"] ?? 0);
        if ($vri === Conf::VIEWREV_ALWAYS) {
            $baseline["can_view_reviewer_identity"] = "yes";
        } else if ($vri === Conf::VIEWREV_IFASSIGNED) {
            $baseline["can_view_reviewer_identity"] = "ifassigned";
        } else if ($vri === Conf::VIEWREV_AFTERREVIEW) {
            $baseline["can_view_reviewer_identity"] = "afterreview";
        } else if ($vri === Conf::VIEWREV_NEVER) {
            $baseline["can_view_reviewer_identity"] = "no";
        } else {
            $baseline["can_view_reviewer_identity"] = array_key_first($groups["can_view_reviewer_identity"]["options"]);
        }

        // 6) pc_can_view_incomplete_submission (pc_seeall)
        $baseline["pc_can_view_incomplete_submission"] = ((int) ($c->settings["pc_seeall"] ?? 0)) > 0 ? "checked" : "unchecked";

        // 7) pc_can_view_submitted_pdf (pc_seeallpdf)
        $baseline["pc_can_view_submitted_pdf"] = ((int) ($c->settings["pc_seeallpdf"] ?? 0)) > 0 ? "checked" : "unchecked";

        // 8) reviewer_view_comments (cmt_revid)
        $baseline["reviewer_view_comments"] = ((int) ($c->settings["cmt_revid"] ?? 0)) > 0 ? "yes" : "only_when_see_reviewer_names";

        // 9) reviewers_view_decision (seedec for reviewers; 0/SEEDEC_REV/SEEDEC_NCREV)
        if ($sd === Conf::SEEDEC_NCREV) {
            $baseline["reviewers_view_decision"] = "yes_unless_conflict";
        } else if ($sd > 0) {
            $baseline["reviewers_view_decision"] = "yes";
        } else {
            $baseline["reviewers_view_decision"] = "no";
        }

        return $baseline;
    }

    /** @param array<string,string> $choice_map
     * @param array<string,array> $groups
     * @param string $pdir */
    private function generate_baseline_separate_csvs($choice_map, $groups, $pdir) {
        // PURPOSE: Generate 9 separate CSV files for the baseline policy
        // Each CSV tests one permission type across all users and papers
        //
        // HOW IT WORKS:
        // 1. Get all users and papers from the database
        // 2. Apply the baseline policy settings to HotCRP
        // 3. For each of the 9 permission types, test every user against every paper
        // 4. Generate one CSV file per permission type showing Permit/Deny decisions
        
        // STEP 1: Collect all users from the database
        $user_ids = [];
        $rs = $this->conf->qe("SELECT contactId FROM ContactInfo");
        while (($row = $rs->fetch_object())) {
            $user_ids[] = (int) $row->contactId;
        }
        Dbl::free($rs);

        // STEP 2: Collect all papers from the database
        $papers = [];
        $prs = $this->conf->qe("SELECT * FROM Paper");
        while (($prow = PaperInfo::fetch($prs, null, $this->conf))) {
            $papers[] = $prow;
        }
        Dbl::free($prs);

        // STEP 3: Apply the baseline policy settings to HotCRP
        // This modifies Conf::$settings in memory to simulate the baseline configuration
        fwrite(STDERR, "DEBUG: Applying baseline policy settings: " . json_encode($choice_map) . "\n");
        $this->apply_policy_settings($choice_map, $groups);

        // STEP 4: Generate separate CSV for each of the 9 permission types
        $settings = [
            "author_names_hidden_from_reviewers" => "author_names_hidden_from_reviewers.csv",
            "author_update_submission" => "author_update_submission.csv", 
            "authors_view_decision" => "authors_view_decision.csv",
            "can_view_review_content" => "can_view_review_content.csv",
            "can_view_reviewer_identity" => "can_view_reviewer_identity.csv",
            "pc_can_view_incomplete_submission" => "pc_can_view_incomplete_submission.csv",
            "pc_can_view_submitted_pdf" => "pc_can_view_submitted_pdf.csv",
            "reviewer_view_comments" => "reviewer_view_comments.csv",
            "reviewers_view_decision" => "reviewers_view_decision.csv"
        ];

        foreach ($settings as $setting_name => $filename) {
            $output_path = $pdir . "/" . $filename;
            $results = [];
            
            // STEP 4a: Test every user against every paper for this permission type
            foreach ($user_ids as $uid) {
                $u = $this->conf->user_by_id($uid);
                if (!$u) {
                    continue;
                }
                
                // Prepare user (clear overrides, set up proper context)
                $uf = $this->prepare_user($u);

                foreach ($papers as $prow) {
                    // Create unique key for this user-paper combination
                    $key = "u{$uf->contactId}_p{$prow->paperId}";
                    
                    // Test this specific permission for this user-paper combination
                    $decision = $this->test_single_setting($uf, $prow, $setting_name);
                    
                    // Store the result
                    $results[$key] = [
                        "user" => "u" . $uf->contactId,
                        "resource" => "p" . $prow->paperId,
                        $setting_name => $decision  // true/false (will be converted to Permit/Deny in CSV)
                    ];
                }
            }

            // STEP 4b: Write CSV file for this permission type
            $this->write_csv($output_path, $results, false, $setting_name);
        }
    }

    /** @param array<string,string> $choice_map
     * @param array<string,string> $baseline
     * @param array<string,array> $groups
     * @param string $pdir */
    private function generate_single_change_csv($choice_map, $baseline, $groups, $pdir) {
        // PURPOSE: Generate 1 CSV file for a single-change policy
        // This tests only the permission type that was changed from baseline
        //
        // HOW IT WORKS:
        // 1. Find which setting changed from baseline
        // 2. Apply the policy settings (baseline + one change)
        // 3. Test only the changed permission type across all users and papers
        // 4. Generate one CSV file showing the impact of this single change
        
        // STEP 1: Find which setting changed from baseline
        $changed_setting = null;
        $changed_option = null;
        foreach ($choice_map as $gkey => $label) {
            if ($label !== $baseline[$gkey]) {
                $changed_setting = $gkey;
                $changed_option = $label;
                break;  // Should only be one change in OAT approach
            }
        }

        if (!$changed_setting) {
            fwrite(STDERR, "No setting changed in policy\n");
            return;
        }

        // STEP 2: Create descriptive filename: setting_option.csv
        // Example: "author_names_hidden_from_reviewers_no.csv"
        $filename = $changed_setting . "_" . $changed_option . ".csv";
        $output_path = $pdir . "/" . $filename;

        // Collect users and papers
        $user_ids = [];
        $rs = $this->conf->qe("SELECT contactId FROM ContactInfo");
        while (($row = $rs->fetch_object())) {
            $user_ids[] = (int) $row->contactId;
        }
        Dbl::free($rs);

        $papers = [];
        $prs = $this->conf->qe("SELECT * FROM Paper");
        while (($prow = PaperInfo::fetch($prs, null, $this->conf))) {
            $papers[] = $prow;
        }
        Dbl::free($prs);

        // Apply policy settings
        fwrite(STDERR, "DEBUG: Applying single-change policy settings: " . json_encode($choice_map) . "\n");
        $this->apply_policy_settings($choice_map, $groups);

        // Generate results for only the changed setting
        $results = [];
        foreach ($user_ids as $uid) {
            $u = $this->conf->user_by_id($uid);
            if (!$u) {
                continue;
            }
            
            $uf = $this->prepare_user($u);

            foreach ($papers as $prow) {
                $key = "u{$uf->contactId}_p{$prow->paperId}";
                $decision = $this->test_single_setting($uf, $prow, $changed_setting);
                $results[$key] = [
                    "user" => "u" . $uf->contactId,
                    "resource" => "p" . $prow->paperId,
                    $changed_setting => $decision
                ];
            }
        }

        // Write single-change CSV with only the changed setting column
        $this->write_csv($output_path, $results, false, $changed_setting);
    }

    /** @param array<string,string> $choice_map
     * @param array<string,array> $groups */
    private function apply_policy_settings($choice_map, $groups) {
        // Apply settings based on policy choices
        foreach ($choice_map as $gkey => $label) {
            switch ($gkey) {
                case "author_names_hidden_from_reviewers":
                    if ($label === "yes") {
                        $this->conf->settings["sub_blind"] = Conf::BLIND_ALWAYS;
                        fwrite(STDERR, "DEBUG: Set sub_blind = " . Conf::BLIND_ALWAYS . " (yes)\n");
                    } else if ($label === "no") {
                        $this->conf->settings["sub_blind"] = Conf::BLIND_NEVER;
                        fwrite(STDERR, "DEBUG: Set sub_blind = " . Conf::BLIND_NEVER . " (no)\n");
                    } else if ($label === "until_review") {
                        $this->conf->settings["sub_blind"] = Conf::BLIND_ALWAYS;
                        fwrite(STDERR, "DEBUG: Set sub_blind = " . Conf::BLIND_ALWAYS . " (until_review)\n");
                    } else if ($label === "depends") {
                        $this->conf->settings["sub_blind"] = Conf::BLIND_OPTIONAL;
                        fwrite(STDERR, "DEBUG: Set sub_blind = " . Conf::BLIND_OPTIONAL . " (depends)\n");
                    }
                    break;
                case "author_update_submission":
                    $this->conf->settings["sub_freeze"] = ($label === "freeze_submissions") ? 1 : 0;
                    break;
                case "authors_view_decision":
                    $this->conf->settings["seedec"] = ($label === "yes") ? Conf::SEEDEC_REV : 0;
                    break;
                case "can_view_review_content":
                    if ($label === "yes") {
                        $this->conf->settings["viewrev"] = Conf::VIEWREV_ALWAYS;
                        $this->conf->settings["viewrev_ext"] = Conf::VIEWREV_ALWAYS;
                    } else if ($label === "unless_incomplete") {
                        $this->conf->settings["viewrev"] = Conf::VIEWREV_UNLESSINCOMPLETE;
                        $this->conf->settings["viewrev_ext"] = Conf::VIEWREV_UNLESSINCOMPLETE;
                    } else if ($label === "unless_any_incomplete") {
                        $this->conf->settings["viewrev"] = Conf::VIEWREV_UNLESSANYINCOMPLETE;
                        $this->conf->settings["viewrev_ext"] = Conf::VIEWREV_UNLESSANYINCOMPLETE;
                    } else if ($label === "after_review") {
                        $this->conf->settings["viewrev"] = Conf::VIEWREV_AFTERREVIEW;
                        $this->conf->settings["viewrev_ext"] = Conf::VIEWREV_AFTERREVIEW;
                    }
                    break;
                case "can_view_reviewer_identity":
                    if ($label === "yes") {
                        $this->conf->settings["viewrevid"] = Conf::VIEWREV_ALWAYS;
                        $this->conf->settings["viewrevid_ext"] = Conf::VIEWREV_ALWAYS;
                    } else if ($label === "ifassigned") {
                        $this->conf->settings["viewrevid"] = Conf::VIEWREV_IFASSIGNED;
                        $this->conf->settings["viewrevid_ext"] = Conf::VIEWREV_IFASSIGNED;
                    } else if ($label === "afterreview") {
                        $this->conf->settings["viewrevid"] = Conf::VIEWREV_AFTERREVIEW;
                        $this->conf->settings["viewrevid_ext"] = Conf::VIEWREV_AFTERREVIEW;
                    } else if ($label === "no") {
                        $this->conf->settings["viewrevid"] = Conf::VIEWREV_NEVER;
                        $this->conf->settings["viewrevid_ext"] = Conf::VIEWREV_NEVER;
                    }
                    break;
                case "pc_can_view_incomplete_submission":
                    $this->conf->settings["pc_seeall"] = ($label === "checked") ? 1 : 0;
                    break;
                case "pc_can_view_submitted_pdf":
                    $this->conf->settings["pc_seeallpdf"] = ($label === "checked") ? 1 : 0;
                    break;
                case "reviewer_view_comments":
                    $this->conf->settings["cmt_revid"] = ($label === "yes") ? 1 : 0;
                    break;
                case "reviewers_view_decision":
                    if ($label === "no") {
                        $this->conf->settings["seedec"] = 0;
                        fwrite(STDERR, "DEBUG: Set seedec = 0 (no)\n");
                    } else if ($label === "yes") {
                        $this->conf->settings["seedec"] = Conf::SEEDEC_REV;
                        fwrite(STDERR, "DEBUG: Set seedec = " . Conf::SEEDEC_REV . " (yes)\n");
                    } else if ($label === "yes_unless_conflict") {
                        $this->conf->settings["seedec"] = Conf::SEEDEC_NCREV;
                        fwrite(STDERR, "DEBUG: Set seedec = " . Conf::SEEDEC_NCREV . " (yes_unless_conflict)\n");
                    }
                    break;
            }
        }
        
        $this->conf->refresh_settings();
        Contact::update_rights();
    }

    /** @param Contact $user
     * @param PaperInfo $prow
     * @return bool */
    private function can_view_comments($user, $prow) {
        // Create a dummy comment to test visibility
        $dummy_comment = new CommentInfo();
        $dummy_comment->conf = $this->conf;
        $dummy_comment->prow = $prow;
        $dummy_comment->paperId = $prow->paperId;
        $dummy_comment->commentId = 1;
        $dummy_comment->contactId = 999;
        $dummy_comment->commentType = CommentInfo::CTVIS_REVIEWER | CommentInfo::CT_TOPIC_PAPER;
        
        // Only test comment visibility if user has some role on this paper (like original script)
        $is_author = $user->act_author_view($prow);
        $is_reviewer = $user->is_reviewer();
        $is_pc = $user->isPC;
        $is_admin = $user->can_administer($prow);
        
        if ($is_author || $is_reviewer || $is_pc || $is_admin) {
            return $user->can_view_comment($prow, $dummy_comment);
        } else {
            return false; // No role = no access
        }
    }

    /** @param Contact $user
     * @return Contact */
    private function prepare_user($user) {
        // Clear overrides; simulate UI force for chairs
        if (method_exists($user, 'with_overrides')) {
            $user = $user->with_overrides(0);
        } else if (method_exists($user, 'set_overrides')) {
            $user->set_overrides(0);
        } else if (property_exists($user, 'overrides')) {
            $user->overrides = 0;
        }
        $uf = $user;
        if ($user->is_manager()) {
            if (method_exists($user, 'with_overrides')) {
                $uf = $user->with_overrides(Contact::OVERRIDE_CONFLICT);
            } else if (method_exists($user, 'set_overrides')) {
                $user->set_overrides(Contact::OVERRIDE_CONFLICT);
                $uf = $user;
            }
        }
        $this->conf->user = $uf;
        return $uf;
    }

    /** @param Contact $user
     * @param PaperInfo $prow
     * @param string $setting
     * @return bool */
    private function test_single_setting($user, $prow, $setting) {
        // Simple: just call the permission function and return the result
        switch ($setting) {
            case "author_names_hidden_from_reviewers":
                return $user->can_view_authors($prow);
            case "author_update_submission":
                return $user->can_edit_paper($prow);
            case "authors_view_decision":
                return $user->can_view_decision($prow);
            case "can_view_review_content":
                return $user->can_view_review($prow, null);
            case "can_view_reviewer_identity":
                return $user->can_view_review_identity($prow);
            case "pc_can_view_incomplete_submission":
                return $user->can_view_paper($prow, false);
            case "pc_can_view_submitted_pdf":
                return $user->can_view_paper($prow, true);
            case "reviewer_view_comments":
                return $this->can_view_comments($user, $prow);
            case "reviewers_view_decision":
                return $user->can_view_decision($prow);
            default:
                return false;
        }
    }

    /** @param string $output_path
     * @param array $results
     * @param bool $is_baseline
     * @param string|null $single_setting */
    private function write_csv($output_path, $results, $is_baseline, $single_setting = null) {
        $fh = fopen($output_path, "w");
        if ($fh === false) {
            fwrite(STDERR, "Cannot open $output_path for writing\n");
            return;
        }

        if ($is_baseline) {
            // Write baseline CSV with all 9 columns
            fputcsv($fh, [
                "User", "Resource", "author_names_hidden_from_reviewers", "author_update_submission", "authors_view_decision",
                "can_view_review_content", "can_view_reviewer_identity", "pc_can_view_incomplete_submission", "pc_can_view_submitted_pdf", 
                "reviewer_view_comments", "reviewers_view_decision"
            ], ",");

            foreach ($results as $row) {
                fputcsv($fh, [
                    $row["user"], $row["resource"], 
                    $row["author_names_hidden_from_reviewers"] ? "Permit" : "Deny",
                    $row["author_update_submission"] ? "Permit" : "Deny",
                    $row["authors_view_decision"] ? "Permit" : "Deny",
                    $row["can_view_review_content"] ? "Permit" : "Deny",
                    $row["can_view_reviewer_identity"] ? "Permit" : "Deny",
                    $row["pc_can_view_incomplete_submission"] ? "Permit" : "Deny",
                    $row["pc_can_view_submitted_pdf"] ? "Permit" : "Deny",
                    $row["reviewer_view_comments"] ? "Permit" : "Deny",
                    $row["reviewers_view_decision"] ? "Permit" : "Deny"
                ], ",");
            }
        } else {
            // Write single-change CSV with "Decision" column name
            fputcsv($fh, ["User", "Resource", "Decision"], ",");

            foreach ($results as $row) {
                fputcsv($fh, [
                    $row["user"], $row["resource"], 
                    $row[$single_setting] ? "Permit" : "Deny"
                ], ",");
            }
        }

        fclose($fh);
    }

    static function run_args($argv) {
        $arg = (new Getopt)->long("name:,n: !", "config: !", "help,h !")
            ->helpopt("help")
            ->description("Run Baseline + OAT policies; collect per-policy CSVs and manifest.")
            ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return (new PolicyRunner_Batch($conf->root_user()))->run();
    }
}


