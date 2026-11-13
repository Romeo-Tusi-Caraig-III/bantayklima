# BantayKlima CI CD Laboratory Report

**Subject:** [Your subject]  
**Laboratory Module 14:** CI/CD Pipeline with GitHub Actions and Docker  
**Group members:** [Your names]  
**Instructor:** [Your instructor]  
**Submission date:** [Your date]

## Introduction and objectives

Continuous integration (CI) checks changes through automated linting, tests and builds. Continuous delivery keeps each successful build ready to release. Continuous deployment goes one step further by automatically rolling successful changes out to a running environment. BantayKlima uses continuous delivery. GitHub Actions publishes a tested container image, and deployment to a host is a separate action.

The objective is to version the application, automate checks and builds, demonstrate safe use of a repository secret, publish a container to GitHub Container Registry (GHCR), and verify successful and failed runs. BantayKlima replaces the handout’s sample SIS. It uses PHP, Apache and SQLite, with JavaScript on the frontend. Git tracks changes, GitHub hosts the repository, GitHub Actions executes the workflow, Docker packages the app, and GHCR stores its images. Node.js checks frontend syntax.

## Procedure and implementation

### 1. Verify the app locally

Start Docker Desktop. Run these commands in PowerShell from the project folder.

```powershell
cd C:\xampp\htdocs\bantayklima
Get-ChildItem assets/*.js | ForEach-Object { node --check $_.FullName; if ($LASTEXITCODE) { throw 'JavaScript syntax check failed' } }
docker build --target checks -t bantayklima:checks .
docker compose up --build -d --wait
docker compose ps
```

The Dockerfile’s `checks` stage lints PHP and runs the test suites. The production stage packages Apache, PHP and the application, and checks the risk API for health. Open [the local dashboard](http://localhost:8080/) and capture the page and healthy container output for your report.

### 2. Create and connect your GitHub repository

Create a repository named `bantayklima` with `main` as the default branch. For the commands below, create it empty because the project already has a README. If your instructor requires a public repository, confirm its visibility before uploading. Use only classroom source code. The project ignores `.env`, `config.local.php`, SQLite data, resident uploads, and local PDFs.

In GitHub, open **Settings → Secrets and variables → Actions → New repository secret**. Create `DEMO_API_KEY` using the dummy value `classroom-demo-12345`. This demonstrates secret injection. GHCR publication uses the workflow’s built-in `GITHUB_TOKEN` with `packages: write`. The workflow checks whether the demo secret exists and prints only the result.

```powershell
git status --short --branch
git check-ignore .env config.local.php data/bantayklima.sqlite
git add .
git diff --cached --name-only
```

Git has already been initialized on `main` in this project. Review the staged file list before committing. The current Git author name is a placeholder. Set your own name and preferred GitHub commit email in this repository. Replace all placeholders below.

```powershell
git config --local user.name "YOUR FULL NAME"
git config --local user.email "YOUR GITHUB COMMIT EMAIL"
git commit -m "Implement BantayKlima CI-CD pipeline"
git remote add origin https://github.com/YOUR_USERNAME/bantayklima.git
git push -u origin main
```

### 3. Explain the workflow

1. A push, pull request, or manual dispatch starts `.github/workflows/ci-cd.yml`. `actions/checkout` makes the triggering source revision available on the runner.
2. The `verify` job builds the PHP test stage, checks JavaScript syntax, verifies the classroom secret on publishable pushes and manual runs, and builds and smoke-tests the production container. The smoke test checks the dashboard and selected JSON endpoints. The workflow also checks that private application paths are blocked.
3. The `deliver` job waits for `verify`. It publishes on a successful default-branch push or a successful push of a `v*` tag. Pull requests and manual runs do not publish images.
4. The default branch publishes `latest` and `sha-<commit>`. Version tags publish the matching release tag and `sha-<commit>`. The version build leaves `latest` as the default branch’s tag.

### 4. Verify the published image

Open **Actions** and capture a run showing both jobs succeeded. Open **Packages**, find the image, and capture its tags. If the package is private, use an account authorized to read it. In the commands below, use your actual lowercase GitHub username.

```powershell
$labImage = 'ghcr.io/your_username/bantayklima:latest'
docker pull $labImage
docker run --rm -d --name bantayklima-published -p 127.0.0.1:8081:80 $labImage
```

Open [the published-image demo](http://localhost:8081/) and capture the page. The temporary container starts with a fresh database. When finished, run `docker stop bantayklima-published`.

## Required challenges and evidence

| Challenge | Procedure | Evidence to capture |
| --- | --- | --- |
| Failed test and recovery | On a disposable branch, change one expected result in `tests/risk_test.php`, commit and push, then open a PR. Confirm the PHP test step fails. Restore the expected result, commit and push again. | Failed test output, skipped delivery, and the passing rerun. If the instructor requires a failure on the actual publishing branch, do it in a separate lab repository without a live deployment. |
| Pull request automation | Create `feature/dashboard-message`, edit the visible dashboard introduction in `index.html`, commit and push, then open a PR into `main`. | Passing PR checks and skipped delivery. |
| Version tagging | On the verified `main` revision, run `git tag v1.0.0` and `git push origin v1.0.0`. | Successful tag run and the GHCR `v1.0.0` tag. |
| Updated image | Merge a reviewed update to the dashboard message, for example “Welcome to BantayKlima - Version 2.0!” Pull and run the new SHA-tagged image. | New image tag and screenshot of the updated message. |

## Results and discussion

- Local JavaScript check: passed.
- Local PHP lint and tests: the Docker `checks` stage passed. The production container built, reported healthy, served the dashboard with HTTP 200, and returned 30 municipalities and valid timeline JSON.
- Local failure and recovery: changing the expected level from Normal to Watch in a disposable copy triggered `FAIL normal conditions`. Rerunning the original risk suite returned 50 passes. This demonstrates local test detection. The GitHub failure and recovery screenshots are still required.
- GitHub repository and Actions run: [Add repository URL and successful run URL].
- Failed test and recovery: [Add failed and recovered run links and screenshots].
- GHCR publication and pull: [Add package URL, tag or digest, and running screenshot].

Use your own evidence from GitHub for the remote results. The local build cannot establish that GitHub ran the workflow or published an image.

## Guide questions

1. **What is CI/CD and why does it matter?** It automates checks and delivery, giving teams repeatable feedback on each change.
2. **Integration, delivery and deployment?** Integration checks merged changes regularly. Delivery prepares a tested release. Deployment automatically puts it into a running environment.
3. **Why lint?** Linting catches syntax problems early. This project runs PHP lint and Node’s JavaScript syntax check.
4. **Why test before deployment?** Tests catch regressions before they reach users or get packaged as a release.
5. **Workflow, job and step?** A workflow is the complete automation. A job is a group of steps on a runner. A step is one action or command in that job.
6. **What does `on` do?** It declares which events start the workflow.
7. **Why `needs: ci`?** It gates publishing on successful CI. BantayKlima calls its CI job `verify`, so it uses `needs: verify`.
8. **What is a Dockerfile?** A recipe that defines how to assemble an image, including its base, files, setup and runtime behavior.
9. **Image vs. container?** An image is the packaged template. A container is a running or stopped instance of it.
10. **What is GHCR?** A registry where workflows can publish versioned images and authorized users can pull them.
11. **Why use GitHub Secrets?** They keep credentials outside committed source and allow GitHub to inject them into authorized jobs. A normal environment variable is a runtime setting and is not automatically protected.
12. **What if a test or step fails?** The verification job fails, and delivery is skipped. Cleanup steps configured with `always()` still run.
13. **How does Actions help collaboration?** Everyone sees the same checks and results on commits and PRs, so reviewers can use the evidence before merging.
14. **What are automatic deployment risks?** A regression or bad configuration can roll out immediately. Required reviews, staged rollouts, and rollback procedures reduce the impact.
15. **How would you improve it?** Add browser regression tests, scan images for vulnerable dependencies, and use protected branches and an approval gate for production rollout.

## Handout discussion questions

1. **What happens when a developer pushes to main?** GitHub starts the workflow. The runner checks out the commit, lints the code, runs tests, checks the demo secret, and builds and smoke-tests the app. Delivery publishes to GHCR after verification succeeds.
2. **What is the purpose of actions/checkout?** It retrieves the repository at the revision that triggered the run so the runner can read the app, workflow inputs, and Dockerfile.
3. **What is the difference between a job and a step?** A job groups related tasks on one runner. A step is one command or reusable action within the job.
4. **What happens when a step fails?** The job normally fails, later ordinary steps are skipped, and dependent delivery is blocked. Cleanup configured with `always()` can still run.
5. **Why is automation important?** It runs the same checks on every change, reports problems quickly, and produces a repeatable build without relying on someone to remember each command.

### GitHub Secrets discussion

1. **Why keep API keys out of GitHub commits?** Anyone with repository access could read them from source or history, and public commits can be copied.
2. **What if a password is exposed?** Revoke or rotate it immediately, check for misuse, and remove the exposed value from repository history where appropriate. Deleting the current line leaves earlier copies usable until the credential is revoked.
3. **Why are GitHub Secrets safer?** GitHub stores them separately and makes them available to eligible workflows when requested. The lab prints whether the dummy secret exists while keeping its value out of the logs.
4. **How is a secret different from a regular environment variable?** A secret is protected stored configuration. An environment variable is a way to pass a value to a running process. A workflow can load a secret into an environment variable for a single step.

## Individual contributions and reflection

Each member should fill in their name, actual contribution, a decision they made, and what they learned. Use a specific observation from a build, test, PR, or published image. Sample roles are application developer, workflow author, Docker engineer, tester, and documentation lead.

## Conclusion

BantayKlima’s pipeline checks PHP and JavaScript, runs automated tests, builds a Docker image and can deliver a versioned image to GHCR. Complete the GitHub runs and record their evidence to demonstrate the full pipeline.

## References

- [GitHub Actions workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax)
- [Using GitHub Actions secrets](https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/use-secrets)
- [Publishing Docker images with GitHub Actions](https://docs.github.com/en/actions/tutorials/publish-packages/publish-docker-images)
- [BantayKlima setup](README.md)
