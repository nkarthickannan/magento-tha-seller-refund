# 05 - AI Usage

A candid account of how you used AI. It is framed as transparency and is not held
against you.

------

1. Used to fix the issues during the initial setup
Platform architecture mismatch issue
    DOCKER_DEFAULT_PLATFORM=linux/amd64 docker compose pull
    .env file is created with DOCKER_DEFAULT_PLATFORM=linux/amd64

Opensearch container failed to start
    Fixed with compose.override.yaml file
    Replaced mem_limit with 2g

git remote "fork" is deleted and recreated with "git" URL

Backslashes are creating issues, hence removed them from the following:
    git push fork main 'refs/remotes/origin/review/*:refs/heads/review/*'

    gh pr create --repo <your-username>/magento-tha-seller-refund \\\\
    --base main --head review/pr-01-partial-refund-presentation \\\\
    --title "Align partial-refund presentation and validation" \\\\
    --body-file docs/pull-requests/pr-01-partial-refund-presentation.md

    gh pr create --repo <your-username>/magento-tha-seller-refund \\\\
    --base main --head review/pr-02-erp-refund-sync \\\\
    --title "Add ERP tax mapping and refund retry handling" \\\\
    --body-file docs/pull-requests/pr-02-erp-refund-sync.md

2. After the given architecture and FRD are analysed, used AI to make sure that what I understood is correct and to ensure that I'm not missing anything

3. To understand what code is already written inside the app/code/Acme/SellerRefund module.

4. To try how it works currently in the admin

5. To increase the refund window to 28 days. Because the seeded data makes it impossible to see the "Refund" button

6. To study what does tax-group mean in the context of this module. Found that its related to Japan's tax representation forms

7. To identify the meaning of the code changes in the two given PRs.

8. To draft the submission/01-code-review.md file as a Markdown based on my findings.

9. To draw sequence diagram for submission/02_refund_design.md based on my plan.

10. To frame the steps needed to be taken to sign-off the current architecture. What needs to be done to deliver the module using the current architecture - submission/03-architecture-review.md

11. To write the code and tests. The entire code changes were written with AI only, under my guidance.
