'use client';

import * as React from 'react';
import {useRouter} from 'next/navigation';
import {useForm} from 'react-hook-form';
import {zodResolver} from '@hookform/resolvers/zod';
import * as z from 'zod';
import {Button} from '@/components/ui/button';
import {Input} from '@/components/ui/input';
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {useToast} from '@/hooks/use-toast';
import {Loader2, LogIn} from 'lucide-react';
import {Card, CardContent, CardHeader, CardTitle, CardDescription} from '@/components/ui/card';

const loginFormSchema = z.object({
  zone: z.string().min(1, {message: 'Please select a zone.'}),
  passcode: z.string().min(1, {message: 'Passcode is required.'}),
});

type LoginFormValues = z.infer<typeof loginFormSchema>;

export default function LoginPage() {
  const [zones, setZones] = React.useState<string[]>([]);
  const [isLoading, setIsLoading] = React.useState(false);
  const router = useRouter();
  const {toast} = useToast();

  const form = useForm<LoginFormValues>({
    resolver: zodResolver(loginFormSchema),
    defaultValues: {
      zone: '',
      passcode: '',
    },
  });

  React.useEffect(() => {
    const fetchZones = async () => {
      try {
        const response = await fetch('/api/auth/zone-login');
        if (!response.ok) throw new Error('Failed to fetch zones');
        const data = await response.json();
        console.log(data)
        setZones(data.zones || []);
      } catch (error) {
        toast({
          title: 'Error',
          description: 'Could not load zones for login.',
          variant: 'destructive',
        });
      }
    };
    fetchZones();
  }, [toast]);

  const handleLoginSubmit = async (values: LoginFormValues) => {
    setIsLoading(true);
    try {
      const response = await fetch('/api/auth/zone-login', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(values),
      });

      const data = await response.json();

      if (!response.ok || !data.success) {
        throw new Error(data.message || 'Login failed.');
      }

      // On success, save user data to localStorage and redirect
      if (typeof window !== 'undefined' && window.localStorage) {
        localStorage.setItem('zoneUser', JSON.stringify(data.user));
      }
      
      toast({
        title: 'Login Successful',
        description: `Welcome, ${data.user.name}!`,
      });

      router.push('/');

    } catch (error) {
      toast({
        title: 'Login Failed',
        description: (error as Error).message,
        variant: 'destructive',
      });
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-background px-4">
      <Card className="w-full max-w-sm">
        <CardHeader className="text-center">
          <CardTitle className="text-2xl font-headline">Zone Sign-In</CardTitle>
          <CardDescription>
            Select your zone and enter the passcode to continue.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Form {...form}>
            <form
              onSubmit={form.handleSubmit(handleLoginSubmit)}
              className="space-y-6"
            >
              <fieldset disabled={isLoading} className="space-y-4">
                <FormField
                  control={form.control}
                  name="zone"
                  render={({field}) => (
                    <FormItem>
                      <FormLabel>Zone</FormLabel>
                      <Select
                        onValueChange={field.onChange}
                        defaultValue={field.value}
                      >
                        <FormControl>
                          <SelectTrigger>
                            <SelectValue placeholder="Select your zone" />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {zones.length > 0 ? (
                            zones.map(zone => (
                              <SelectItem key={zone} value={zone}>
                                {zone}
                              </SelectItem>
                            ))
                          ) : (
                            <SelectItem value="loading" disabled>
                              Loading zones...
                            </SelectItem>
                          )}
                        </SelectContent>
                      </Select>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="passcode"
                  render={({field}) => (
                    <FormItem>
                      <FormLabel>Passcode</FormLabel>
                      <FormControl>
                        <Input
                          type="password"
                          placeholder="Enter passcode"
                          {...field}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </fieldset>
              <Button type="submit" className="w-full" disabled={isLoading}>
                {isLoading ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <LogIn className="mr-2 h-4 w-4" />
                )}
                {isLoading ? 'Signing in...' : 'Sign In'}
              </Button>
            </form>
          </Form>
        </CardContent>
      </Card>
    </div>
  );
}
