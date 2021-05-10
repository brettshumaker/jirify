# jirify
Command line tool to log Clockify entries into Jira issue worklogs.

## .data/mapping.json

Right now, this should be a json mapping of your Clockify client names to the Jira issue key:

```
{
    "Client Name"     : "ABC-1",
    "Alt Client Name" : "ABC-1",
    "Other Client"    : "ABC-2"
}