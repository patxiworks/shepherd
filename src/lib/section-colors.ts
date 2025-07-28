

export const sectionColors: { [key: string]: string } = {
  sf: '#FFDDC1', // Light Peach
  sm: '#C1E1FF', // Light Blue
  'c-m': '#D4F8E8', // Mint Green
  'c-w': '#FFFACD', // Lemon Chiffon
  'p-m': '#F8D4D4', // Light Pink
  'p-w': '#E6E6FA', // Lavender
  default: '#F5F5F5', // Light Gray for any section not specified
};

export const laborColors: { [key: string]: string } = {
  sm: '#B2EBF2',   // Light Cyan
  sf: '#FFCCBC',   // Light Coral
  sscc: '#C8E6C9', // Light Green
  'c-m': '#F0F4C3', // Light Lime
  'c-w': '#D1C4E9', // Light Purple
  'p-m': '#FFCDD2', // Light Pink
  'p-w': '#CFD8DC', // Blue Grey
  default: '#FAFAFA', // Off-white
};


export const getSectionColor = (section: string): string => {
  return sectionColors[section] || sectionColors.default;
};

export const getLaborColor = (labor: string): string => {
  return laborColors[labor] || laborColors.default;
};
