// Execute with mongosh authenticated as an administrator; no credentials in the URI.
const password = process.env.XCRAWLER_MONGO_PASSWORD || passwordPrompt();
if (!password) throw new Error('A nonempty MongoDB password is required');
const database = db.getSiblingDB('xcrawler');
const user = {pwd: password, roles: [{role: 'readWrite', db: 'xcrawler'}]};
if (database.getUser('xcrawler')) {
    database.updateUser('xcrawler', user);
} else {
    database.createUser({user: 'xcrawler', ...user});
}
