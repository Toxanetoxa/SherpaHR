export function normalizeNickname(value: string): string | null {
  const nickname = value.trim().replace(/[^\S\r\n\t]+/gu, ' ');
  const forbidden = /[<>\p{Cc}\u061c\u200e\u200f\u202a-\u202e\u2066-\u2069]/u;
  if (!nickname || nickname.length > 24 || forbidden.test(nickname) || !/[^\s\p{Cf}]/u.test(nickname)) {
    return null;
  }
  return nickname;
}
