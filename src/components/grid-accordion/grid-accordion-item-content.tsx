
import type { CentreData } from '@/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

interface GridAccordionItemContentProps {
  item: CentreData;
}

export function GridAccordionItemContent({ item }: GridAccordionItemContentProps) {
  if (!item.activities || item.activities.length === 0) {
    return <p className="text-muted-foreground py-4 text-center">No activities scheduled for this centre.</p>;
  }
  
  return (
    <div className="px-1 py-2 md:px-2">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Activity</TableHead>
            <TableHead>Day</TableHead>
            <TableHead>Time</TableHead>
            <TableHead>Priest</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {item.activities.map((activity, index) => (
            <TableRow key={index}>
              <TableCell className="font-medium">{activity.activity}</TableCell>
              <TableCell>{activity.day}</TableCell>
              <TableCell>{activity.time}</TableCell>
              <TableCell>{activity.priest || 'N/A'}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
