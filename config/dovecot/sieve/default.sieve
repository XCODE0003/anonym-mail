# Default Sieve script
# Moves spam to Junk folder

require ["fileinto", "mailbox"];

# Move spam to Junk
if header :contains "X-Spam-Flag" "YES" {
    fileinto :create "Junk";
    stop;
}
