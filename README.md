# jirify

Command line tool to log time entries from Clockify or Toggl into Jira issue worklogs. You'll need to have a specific set up for your Jira board, and your Projects/Clients in Clockify or Toggl. This tool expects that you have a Jira board with a single issue for each of your Clients that you want to add work logs to. The "summary" (title) of that issue should be the name of the Client. Then, in Clockify or Toggl, you'll need a Client and a Project for each of those Client issues from Jira. The tool will look at the Client names from Clockify or Toggl and select an issue from Jira with a matching title to log the time to.

## Config

You configure this tool by using the `.config/config.json` file. There's an example file included for you to start from.

- `service`: This is the time tracking service you want to use. This value should be either `Clockify` or `Toggl`.
- `token`: This is your personal access token from the time tracking service you're using. Instructions for finding this token [for Clockify](https://clockify.me/help/faq/where-can-find-api-information) and [for Toggl](https://support.toggl.com/en/articles/3116844-where-is-my-api-token-located).
- `workspace`: This is the workspace ID for the time tracking service you're using.
    - For Clockify, go to the time tracker on the web and click `Settings` in the left sidebar. You'll find the workspace ID in the URL `clockify.me/workspaces/your-workspace-id/settings#settings`.
    - For Toggl, go to the time tracker on the web and click `Settings` in the left sidebar. You'll find the workspace ID in the URL `track.toggl.com/your-workspace-id/settings/general`.
- `user_id`: *Clockify only!* This one is sort of a pain to get. The easiest way I've found is to open up the `Network` tab in Chrome developer tools and go to your user profile by clicking your avatar in the top right corner of the page. Filter the requests by `/users` and you should see several requests in there with a long string of letters/numbers - this is your user ID.
- `jira_token`: This is your API Token for Jira. Go to `https://id.atlassian.com/manage-profile/security/api-tokens` and click Create API token to generate a token.
- `jira_email`: This is the email address associated with your Jira account.
- `jira_project_key`: This is the project key for your Jira project - the string of uppercase letters, numbers, and/or underscores that prefix your issue IDs. 
- `jira_endpoint`: This is the URL to your Jira instance and should look something like `https://your-jira-instance.atlassian.net`
- `timezone`: This should be a timezone string from [this list of supported Timezones in PHP](https://www.php.net/manual/en/timezones.php). If this is missing, or an invalid string is used, the tool will first try and get the timezone from your local machine. If that fails, it will fallback to using `America/New_York`.

## Usage

### Logging Time
From the command line, run this command to log time:

```
php /path/to/jirify/jirify.php log_time
```

### Dry Run
Jirify also accepts a `--dry_run=true` flag to run the command without actually logging time to Jira.

### Dates
Jirify will automatically remember the start time of the last time entry that it logs and then start from there the next time the command is run. The first time you run it, however, it defaults to midnight of the current day. If you want to start at a particular time, you can use the `--start_date` flag and pass in a `YYYY-MM-DD` date string:

```
php /path/to/jirify/jirify.php log_time --start_date=2021-05-10
```

It also accepts `--end_date` in the same format.

### Aliases

I'd recommend adding aliases for the tool in your bash or zshell profiles to make running the command easier. Here's what I use:

```
alias jirify="php ~/Developer/Tools/jirify/jirify.php log_time"
alias jirifydry="php ~/Developer/Tools/jirify/jirify.php log_time --dry_run=true"
```

## Alternate Client Names

If the Client names you have in your time tracking service don't perfectly match the issue summary in Jira, you can add alternate names (or nicknames) for the clients in `.config/nicknames.json`. The data should have the client nickname as the key and the Jira issue key as the value. If your Jira project key is `ABC`, your file might look like this:

```
{
    "Client Name"     : "ABC-1",
    "Alt Client Name" : "ABC-1",
    "Other Client"    : "ABC-2"
}