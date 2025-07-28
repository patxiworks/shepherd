
export const sectionColors: { [key: string]: string } = {
  sf: '#FFDDC1', // Light Peach
  sm: '#C1E1FF', // Light Blue
  'c-m': '#D4F8E8', // Mint Green
  'c-w': '#FFFACD', // Lemon Chiffon
  'p-m': '#F8D4D4', // Light Pink
  'p-w': '#E6E6FA', // Lavender
  default: '#F5F5F5', // Light Gray for any section not specified
};

export const getSectionColor = (section: string): string => {
  return sectionColors[section] || sectionColors.default;
};
