# GitHub Setup Guide

Since you've integrated GitHub with Cursor, here's how to push your code:

## Step 1: Create Repository on GitHub

1. Go to https://github.com/new
2. **Repository name**: `meta-conversions-api`
3. **Description**: `WordPress plugin for Facebook Conversions API tracking`
4. **Visibility**: Choose:
   - **Private** (recommended for now) - Only you can see it
   - **Public** - Anyone can see/download
5. **DO NOT** check "Add README" or ".gitignore" (we already have them)
6. Click **Create repository**

## Step 2: Initialize Git in Cursor

Open the terminal in Cursor (View → Terminal) and run:

```bash
cd "/Users/lutherharrity/Library/CloudStorage/OneDrive-NestwiseMarketingPartners/Dev/Meta Conversions API"

# Initialize git
git init

# Add all files
git add .

# Make first commit
git commit -m "Initial release v1.0.0"

# Add GitHub as remote (REPLACE 'wpbooster' with your GitHub username)
git remote add origin https://github.com/wpbooster/meta-conversions-api.git

# Push to GitHub
git push -u origin main
```

## Step 3: Create Your First Release

1. Go to your repository on GitHub
2. Click **Releases** (right side)
3. Click **Create a new release**
4. Fill in:
   - **Tag version**: `v1.0.0`
   - **Release title**: `Meta Conversions API v1.0.0`
   - **Description**: Copy from CHANGELOG.md
5. Click **Publish release**

## Step 4: Future Updates

When you make changes:

```bash
# Make your code changes in Cursor
# Then commit and push:

git add .
git commit -m "Description of changes"
git push

# For a new version release:
# 1. Update version in meta-conversions-api.php
# 2. Update CHANGELOG.md
# 3. Commit and push
# 4. Create new release on GitHub with new version tag
```

## Using Cursor's Git Integration

Cursor has built-in Git support:

1. **See Changes**: Look at the Source Control panel (Ctrl+Shift+G)
2. **Stage Changes**: Click the + icon next to changed files
3. **Commit**: Type message and click ✓
4. **Push**: Click the "..." menu → Push

## Troubleshooting

### "Permission denied" error:
You need to authenticate. Run:
```bash
gh auth login
```
(GitHub CLI) or set up SSH keys

### "Repository not found":
Make sure you replaced 'wpbooster' with your actual GitHub username in the remote URL

### Check current remote:
```bash
git remote -v
```

## Need Help?

Just ask! I can walk you through each step.

