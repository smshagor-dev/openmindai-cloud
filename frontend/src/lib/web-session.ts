export const webSessionCookie = "openmindai_token";
export const webCookieOptions = {
  httpOnly: true,
  secure: process.env.NODE_ENV === "production",
  sameSite: "lax" as const,
  path: "/",
};
export const webSessionOptions = { ...webCookieOptions, maxAge: 30 * 86400 };
