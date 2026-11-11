// webpack replaces process.env.NODE_ENV at build time (and Jest provides it).
declare const process: { env: { NODE_ENV?: string } };
